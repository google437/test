<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, array $details = [], ?int $orgId = null, ?int $userId = null, ?DB $db = null): void
    {
        $db = $db ?? DB::app();
        $db->insert('audit_log', [
            'org_id' => $orgId ?? Auth::orgId(),
            'user_id' => $userId ?? Auth::userId(),
            'action' => $action,
            'details' => json_encode($details),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ], '');
    }
}
