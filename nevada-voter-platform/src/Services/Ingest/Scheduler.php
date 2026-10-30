<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Config;
use App\Core\DB;
use App\Core\Log;

/**
 * Decides which jobs are due. Called every 15 minutes by cron (bin/ingest run-due).
 *  clark-voters      daily 06:00 PT
 *  clark-inactive    daily 06:15 PT
 *  clark-canceled    daily 06:15 PT
 *  clark-mail        weekly; every 4 hours inside an election window (registration close -> election day)
 *  clark-ev          every 2 hours during early voting
 *  sos-statewide     monthly reminder only (manual upload until automation is approved)
 */
final class Scheduler
{
    public function __construct(private DB $db) {}

    public function due(?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone(Config::get('TIMEZONE', 'America/Los_Angeles')));
        $jobs = [];
        $state = [];
        foreach ($this->db->all('SELECT * FROM source_state') as $s) {
            $state[$s['source']] = $s;
        }
        $window = $this->db->one('SELECT * FROM election_windows WHERE is_current ORDER BY election_date DESC LIMIT 1');
        $today = $now->format('Y-m-d');
        $inWindow = $window && $window['registration_close'] && $today >= $window['registration_close'] && $today <= $window['election_date'];
        $inEv = $window && $window['early_vote_start'] && $today >= $window['early_vote_start'] && $today <= ($window['early_vote_end'] ?? $window['election_date']);

        $daily = fn(string $src, string $at) => $now->format('H:i') >= $at && (empty($state[$src]['last_attempt_at']) || substr((string) $state[$src]['last_attempt_at'], 0, 10) < $today);
        $every = fn(string $src, int $hours) => empty($state[$src]['last_attempt_at']) || (strtotime((string) $state[$src]['last_attempt_at']) <= $now->getTimestamp() - $hours * 3600);

        if ($daily('clark-voters', '06:00')) {
            $jobs[] = 'clark-voters';
        }
        if (Config::get('CLARK_INACTIVE_URL') && $daily('clark-inactive', '06:15')) {
            $jobs[] = 'clark-inactive';
        }
        if (Config::get('CLARK_CANCELED_URL') && $daily('clark-canceled', '06:15')) {
            $jobs[] = 'clark-canceled';
        }
        if (Config::get('CLARK_MAIL_URL') && ($inWindow ? $every('clark-mail', 4) : $every('clark-mail', 24 * 7))) {
            $jobs[] = 'clark-mail';
        }
        if (Config::get('CLARK_EV_URL') && $inEv && $every('clark-ev', 2)) {
            $jobs[] = 'clark-ev';
        }
        if ($every('sos-statewide', 24 * 30)) {
            $jobs[] = 'sos-reminder';
        }
        return $jobs;
    }

    public function runDue(): array
    {
        $out = [];
        // Held runs that staff approved in the admin console are applied here, with owner privileges.
        foreach ($this->db->all("SELECT id FROM import_runs WHERE status = 'approved' ORDER BY id") as $r) {
            $out['approve#' . $r['id']] = (new Pipeline($this->db))->approve((int) $r['id'], 0);
        }
        foreach ($this->due() as $job) {
            Log::info("Scheduler: running $job");
            $out[$job] = match ($job) {
                'clark-voters', 'clark-active' => (new Pipeline($this->db))->run($job),
                'clark-inactive', 'clark-canceled' => (new StatusFiles($this->db))->run($job),
                'clark-mail', 'clark-ev' => (new BallotFiles($this->db))->run($job),
                'sos-reminder' => $this->sosReminder(),
            };
        }
        return $out;
    }

    private function sosReminder(): array
    {
        $this->db->exec("INSERT INTO source_state (source, last_attempt_at) VALUES ('sos-statewide', now()) ON CONFLICT (source) DO UPDATE SET last_attempt_at = now()");
        \App\Core\Alerts::send('Monthly reminder: SOS statewide list', 'Download the NevVoter statewide list and upload it with: php bin/ingest sos-upload --file=<path>');
        return ['status' => 'reminder_sent'];
    }
}
