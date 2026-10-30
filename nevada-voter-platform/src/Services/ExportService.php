<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Alerts;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;

/** Logged, size-capped CSV exports (compliance: every export records user, filter, row count, time). */
final class ExportService
{
    public static function listCsv(DB $db, array $list): never
    {
        $max = Config::int('EXPORT_MAX_ROWS', 5000);
        $alertAt = Config::int('EXPORT_ALERT_ROWS', 1000);
        $counts = ListQuery::listCounts($db, $list);
        $n = (int) $counts['voters'];
        if ($n > $max) {
            http_response_code(413);
            echo "This export would contain " . number_format($n) . " rows; the cap is " . number_format($max) . ". Narrow the list and try again.";
            exit;
        }
        $rows = ListQuery::members($db, $list, $max, 0);
        $exportId = $db->insert('exports', [
            'org_id' => Auth::orgId(), 'user_id' => Auth::userId(), 'kind' => 'list_csv', 'filters' => json_encode(['list_id' => $list['id'], 'name' => $list['name'], 'filters' => $list['filters']]),
            'row_count' => count($rows), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        Audit::log('export.list', ['list_id' => $list['id'], 'rows' => count($rows), 'export_id' => $exportId]);
        if (count($rows) >= $alertAt) {
            $org = Auth::org();
            Alerts::send('Large export', sprintf('%s exported %d rows from list "%s" (org %s, export #%d).', Auth::user()['email'] ?? 'user', count($rows), $list['name'], $org['name'] ?? '?', $exportId));
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="list-' . (int) $list['id'] . '-' . date('Ymd-Hi') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['voter_id', 'county_voter_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'age', 'sex', 'party', 'status', 'phone', 'address', 'unit', 'city', 'zip', 'precinct', 'registration_date', 'generals_of_last_4', 'primaries_of_last_4', 'last_voted', 'voted_this_election']);
        foreach ($rows as $v) {
            fputcsv($out, [$v['id'], $v['county_voter_id'], $v['first_name'], $v['middle_name'], $v['last_name'], $v['suffix'], age_from_year($v['birth_year'] ? (int) $v['birth_year'] : null), $v['sex'], $v['party'], $v['status'], $v['phone'],
                voter_address($v), $v['res_unit'], $v['res_city'], $v['res_zip'], $v['precinct_number'], $v['registration_date'], $v['vote_count_general'], $v['vote_count_primary'], $v['last_voted_election'], $v['voted_current'] ? 'yes' : 'no']);
        }
        fclose($out);
        exit;
    }

    public static function surveyCsv(DB $db, int $orgId): never
    {
        $max = Config::int('EXPORT_MAX_ROWS', 5000);
        $rows = $db->all('SELECT sr.id, sr.answer, q.question, ca.voter_id, ca.channel, ca.result, ca.contacted_at, u.name AS user_name, pr.number AS precinct
            FROM survey_responses sr JOIN survey_questions q ON q.id = sr.question_id JOIN contact_attempts ca ON ca.id = sr.contact_attempt_id
            JOIN voters v ON v.id = ca.voter_id LEFT JOIN precincts pr ON pr.id = v.precinct_id LEFT JOIN users u ON u.id = ca.user_id
            WHERE sr.org_id = :o ORDER BY ca.contacted_at DESC LIMIT :l', [':o' => $orgId, ':l' => $max + 1]);
        if (count($rows) > $max) {
            http_response_code(413);
            echo 'Survey export exceeds the cap of ' . number_format($max) . ' rows. Contact an administrator for a bulk export.';
            exit;
        }
        $exportId = $db->insert('exports', ['org_id' => $orgId, 'user_id' => Auth::userId(), 'kind' => 'survey_csv', 'filters' => '{}', 'row_count' => count($rows), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        Audit::log('export.survey', ['rows' => count($rows), 'export_id' => $exportId]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="survey-' . date('Ymd-Hi') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['response_id', 'voter_id', 'precinct', 'question', 'answer', 'channel', 'result', 'contacted_at', 'by']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['voter_id'], $r['precinct'], $r['question'], $r['answer'], $r['channel'], $r['result'], $r['contacted_at'], $r['user_name']]);
        }
        fclose($out);
        exit;
    }
}
