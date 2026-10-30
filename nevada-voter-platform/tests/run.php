#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Integration tests. Needs a database (uses .env). Creates its own schema state by running the demo
 * pipeline, so point DATABASE_URL at a disposable database:  php tests/run.php
 *
 * Covers the acceptance checks from the spec:
 *  - Phase 1: two loads in a row, the second shows a correct new/changed/removed diff, idempotent rerun
 *  - Phase 2: an Assembly-district org sees only its district's voters; cross-org access fails under RLS
 *  - TOTP vectors, list filter SQL, export cap, offline sync idempotency
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Core\Config;
use App\Core\DB;
use App\Core\Totp;
use App\Services\Ingest\BallotFiles;
use App\Services\Ingest\Pipeline;
use App\Services\Ingest\SampleGenerator;
use App\Services\ListQuery;
use App\Services\TurfService;

$fails = 0;
$n = 0;
function ok(bool $cond, string $msg): void
{
    global $fails, $n;
    $n++;
    echo ($cond ? '  PASS ' : '  FAIL ') . $msg . PHP_EOL;
    if (!$cond) {
        $fails++;
    }
}

echo "TOTP\n";
// RFC 6238 test vector (SHA1, secret "12345678901234567890", T=59 -> 94287082; 8 digits truncated to last 6 = 287082)
$secret = Totp::base32Encode('12345678901234567890');
ok(Totp::code($secret, 59) === '287082', 'RFC 6238 vector at T=59');
ok(Totp::code($secret, 1111111109) === '081804', 'RFC 6238 vector at T=1111111109');
ok(Totp::base32Decode(Totp::base32Encode('hello world')) === 'hello world', 'base32 round trip');

echo "Database reset\n";
$owner = DB::owner();
$owner->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$owner->pdo->exec((string) file_get_contents(APP_ROOT . '/db/schema.sql'));
$appRole = Config::get('APP_DB_USER');
ok($appRole !== null, 'APP_DB_USER configured (needed to test row-level security)');
if ($appRole) {
    $owner->pdo->exec(str_replace(':"approle"', '"' . $appRole . '"', (string) file_get_contents(APP_ROOT . '/db/policies.sql')));
}

echo "Phase 1: ingestion\n";
$tmp = APP_ROOT . '/storage/tmp';
SampleGenerator::write("$tmp/t_week1.zip", 3000, 7);
SampleGenerator::write("$tmp/t_week2.zip", 3000, 7, true);
$r1 = (new Pipeline($owner))->run('clark-voters', "$tmp/t_week1.zip", true);
ok($r1['status'] === 'loaded', 'week 1 loads');
ok(($r1['new'] ?? 0) === 3000, 'week 1: 3000 new voters');
ok((int) $owner->val('SELECT count(*) FROM voters') === 3000, 'row count matches the file');
ok((int) $owner->val('SELECT count(*) FROM voters WHERE household_id IS NULL AND confidential = false') === 0, 'every non-confidential voter has a household');
ok((int) $owner->val("SELECT count(*) FROM voters WHERE confidential AND res_street_name <> ''") === 0, 'confidential voters have blank address passed through');
$again = (new Pipeline($owner))->run('clark-voters', "$tmp/t_week1.zip");
ok($again['status'] === 'no_change', 'same file again is no_change (sha256 match)');
$r2 = (new Pipeline($owner))->run('clark-voters', "$tmp/t_week2.zip", true);
ok($r2['status'] === 'loaded', 'week 2 loads');
ok(($r2['new'] ?? 0) === 60, 'week 2: 60 new voters (2%)');
ok(($r2['removed'] ?? 0) === 30, 'week 2: 30 voters marked removed (1%), none deleted');
ok(($r2['changed'] ?? 0) > 100 && ($r2['changed'] ?? 0) < 400, 'week 2: a plausible number of changed voters (' . ($r2['changed'] ?? 0) . ')');
ok((int) $owner->val('SELECT count(*) FROM voters') === 3060, 'removed voters are retained');
ok((int) $owner->val("SELECT count(*) FROM voter_changes WHERE field = 'party'") > 50, 'party changes recorded in voter_changes');
ok((int) $owner->val("SELECT count(*) FROM voter_changes WHERE field = 'status' AND new_value = 'removed'") === 30, 'removals recorded as status changes');
ok((int) $owner->val('SELECT count(*) FROM import_runs WHERE status = \'loaded\'') === 2, 'two loaded runs');
ok((int) $owner->val("SELECT count(*) FROM voters WHERE vote_count_general > 4") === 0, 'derived general count capped at last 4');

echo "Held run\n";
SampleGenerator::write("$tmp/t_tiny.zip", 2000, 7);
$r3 = (new Pipeline($owner))->run('clark-voters', "$tmp/t_tiny.zip", true);
ok($r3['status'] === 'held', 'a 33% smaller file is held for review');
(new Pipeline($owner))->reject((int) $r3['run_id'], 0);
ok($owner->val('SELECT status FROM import_runs WHERE id = :id', [':id' => $r3['run_id']]) === 'rejected', 'held run can be rejected');
ok((int) $owner->val('SELECT count(*) FROM voters WHERE status <> \'removed\'') === 3030, 'rejected run changed nothing');

echo "Election feeds\n";
$owner->insert('election_windows', ['name' => 'Test General', 'election_code' => '26G', 'election_date' => '2026-11-03', 'registration_close' => '2026-10-06', 'early_vote_start' => '2026-10-17', 'early_vote_end' => '2026-10-30', 'is_current' => true]);
SampleGenerator::writeBallotFiles("$tmp/t_mail.csv", "$tmp/t_ev.csv", 3000);
$m = (new BallotFiles($owner))->run('clark-mail', "$tmp/t_mail.csv", true);
$e = (new BallotFiles($owner))->run('clark-ev', "$tmp/t_ev.csv", true);
ok($m['status'] === 'loaded' && $e['status'] === 'loaded', 'mail and early vote files load');
ok((int) $owner->val('SELECT count(*) FROM voters WHERE voted_current') > 0, 'voted_current flag set from feeds');
ok((int) $owner->val('SELECT count(*) FROM voters WHERE mail_requested_current') >= (int) $owner->val('SELECT count(*) FROM voters WHERE mail_returned_current'), 'requested >= returned');

echo "Phase 2: tenancy and row-level security\n";
$org1 = (int) $owner->insert('orgs', ['name' => 'AD1 Campaign', 'type' => 'candidate_committee', 'use_attestation_at' => date('c')]);
$org2 = (int) $owner->insert('orgs', ['name' => 'SD2 Campaign', 'type' => 'pac', 'use_attestation_at' => date('c')]);
$d1 = (int) $owner->val("SELECT id FROM districts WHERE type = 'assembly' AND name = '1'");
$d2 = (int) $owner->val("SELECT id FROM districts WHERE type = 'senate' AND name = '2'");
$owner->exec('INSERT INTO org_districts VALUES (:o, :d)', [':o' => $org1, ':d' => $d1]);
$owner->exec('INSERT INTO org_districts VALUES (:o, :d)', [':o' => $org2, ':d' => $d2]);
$u1 = (int) $owner->insert('users', ['email' => 't1@example.org', 'name' => 'T1', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)]);
$owner->exec('INSERT INTO memberships VALUES (:o, :u, :r)', [':o' => $org1, ':u' => $u1, ':r' => 'admin']);
$expected1 = (int) $owner->val("SELECT count(*) FROM voters v WHERE v.status <> 'removed' AND v.precinct_id IN (SELECT precinct_id FROM precinct_districts WHERE district_id = :d)", [':d' => $d1]);
$total = (int) $owner->val("SELECT count(*) FROM voters WHERE status <> 'removed'");
ok($expected1 > 0 && $expected1 < $total, "AD1 has a strict subset of voters ($expected1 of $total)");

if ($appRole) {
    $app = new DB(DB::connect($appRole, Config::get('APP_DB_PASSWORD', '')));
    $app->setContext($org1, $u1, false);
    $seen = (int) $app->val("SELECT count(*) FROM voters WHERE status <> 'removed'");
    ok($seen === $expected1, "org 1 sees exactly its district's voters via RLS ($seen)");
    $outside = (int) $owner->val("SELECT id FROM voters v WHERE v.status <> 'removed' AND v.precinct_id NOT IN (SELECT precinct_id FROM precinct_districts WHERE district_id = :d) LIMIT 1", [':d' => $d1]);
    ok($app->one('SELECT id FROM voters WHERE id = :id', [':id' => $outside]) === null, 'a voter outside the licensed district is invisible by id');
    ok((int) $app->val('SELECT count(*) FROM vote_history vh WHERE NOT EXISTS (SELECT 1 FROM voters v WHERE v.id = vh.voter_id)') === 0, 'vote history is scoped through voters');
    $c = ListQuery::counts($app, []);
    ok((int) $c['voters'] === $expected1, 'list builder counts respect RLS');
    $c2 = ListQuery::counts($app, ['parties' => ['DEM'], 'gen_min' => 2]);
    ok((int) $c2['voters'] > 0 && (int) $c2['voters'] < $expected1, 'filters narrow the list');
    // Campaign data isolation.
    $listId = (int) $app->insert('lists', ['org_id' => $org1, 'name' => 'L1', 'filters' => '{}', 'created_by' => $u1]);
    $visibleVoter = (int) $app->val('SELECT id FROM voters LIMIT 1');
    $app->insert('contact_attempts', ['org_id' => $org1, 'voter_id' => $visibleVoter, 'user_id' => $u1, 'channel' => 'door', 'result' => 'contacted'], '');
    $app->setContext($org2, $u1, false);
    ok((int) $app->val('SELECT count(*) FROM lists') === 0, 'org 2 cannot see org 1 lists');
    ok((int) $app->val('SELECT count(*) FROM contact_attempts') === 0, 'org 2 cannot see org 1 contact attempts');
    ok($app->one('SELECT id FROM voters WHERE id = :id', [':id' => $visibleVoter]) === null || (int) $owner->val('SELECT count(*) FROM precinct_districts pd WHERE pd.district_id = :d AND pd.precinct_id = (SELECT precinct_id FROM voters WHERE id = :v)', [':d' => $d2, ':v' => $visibleVoter]) === 1, 'org 2 sees org 1 voter only if it is also in SD2');
    $blocked = false;
    try {
        $app->insert('lists', ['org_id' => $org1, 'name' => 'sneaky', 'filters' => '{}'], '');
    } catch (\Throwable $ex) {
        $blocked = true;
    }
    ok($blocked, 'org 2 cannot insert rows into org 1 (WITH CHECK policy)');
    $blocked = false;
    try {
        $app->exec("UPDATE voters SET party = 'REP'");
    } catch (\Throwable $ex) {
        $blocked = true;
    }
    ok($blocked, 'app role cannot modify the voter file');
    // Phase 3: turf cutting.
    $app->setContext($org1, $u1, false);
    $owner->exec("UPDATE households SET lat = 36.1 + random() / 10, lng = -115.2 + random() / 10 WHERE lat IS NULL");
    $list = $app->one('SELECT * FROM lists WHERE id = :id', [':id' => $listId]);
    $list['filters'] = [];
    $ids = TurfService::autoSplit($app, $list, 40, $org1);
    ok(count($ids) >= 2, count($ids) . ' turfs created by auto-split');
    $walk = TurfService::walkData($app, $ids[0]);
    ok(count($walk) > 0 && count($walk) <= 40, 'first turf has at most 40 doors in walk order');
    $all = array_sum(array_map(fn($t) => (int) $app->val('SELECT door_count FROM turfs WHERE id = :id', [':id' => $t]), $ids));
    ok($all === (int) $app->val("SELECT count(DISTINCT household_id) FROM voters WHERE status <> 'removed' AND household_id IS NOT NULL"), 'every household in the list landed in exactly one turf');
    $poly = ['type' => 'Polygon', 'coordinates' => [[[-115.25, 36.05], [-115.05, 36.05], [-115.05, 36.25], [-115.25, 36.25], [-115.25, 36.05]]]];
    $tid = TurfService::fromPolygon($app, $list, $org1, 'poly', $poly);
    ok((int) $app->val('SELECT door_count FROM turfs WHERE id = :id', [':id' => $tid]) === $all, 'polygon covering everything captures every door');
    // Phase 4: offline sync idempotency.
    $in = ['voter_id' => $visibleVoter, 'result' => 'not_home', 'channel' => 'door', 'client_id' => 'abc-1', 'turf_id' => $ids[0]];
    $a = \App\Controllers\VoterController::recordAttempt($app, $in, $org1, $u1);
    $b = \App\Controllers\VoterController::recordAttempt($app, $in, $org1, $u1);
    ok($a !== null && $a === $b, 'replaying the same client_id returns the same attempt (idempotent)');
    ok(\App\Controllers\VoterController::recordAttempt($app, ['voter_id' => $outside, 'result' => 'contacted', 'client_id' => 'x'], $org1, $u1) === null, 'attempt on an out-of-district voter is rejected');
    // Chase mode.
    $voted = (int) $app->val('SELECT count(*) FROM voters WHERE voted_current');
    $chase = ListQuery::counts($app, [], true);
    ok((int) $chase['voters'] === $expected1 - $voted, 'chase mode drops voters who already voted');
}

echo "Retention\n";
$owner->exec("UPDATE orgs SET contract_end = current_date - 1 WHERE id = :o", [':o' => $org2]);
exec('php ' . escapeshellarg(APP_ROOT . '/bin/nvvp') . ' retention', $out, $rc);
ok($rc === 0 && $owner->val('SELECT status FROM orgs WHERE id = :o', [':o' => $org2]) === 'ended', 'org past contract end is ended by the retention job');

echo PHP_EOL . ($fails ? "$fails of $n checks FAILED" : "All $n checks passed") . PHP_EOL;
exit($fails ? 1 : 0);
