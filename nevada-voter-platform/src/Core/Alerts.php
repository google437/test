<?php
declare(strict_types=1);

namespace App\Core;

/** Admin alerts: Slack incoming webhook plus email. Never include voter PII in an alert. */
final class Alerts
{
    public static function send(string $subject, string $body): void
    {
        $slack = Config::get('SLACK_WEBHOOK_URL');
        if ($slack) {
            $payload = json_encode(['text' => "*{$subject}*\n{$body}"]);
            $ch = curl_init($slack);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            curl_close($ch);
        }
        $email = Config::get('ALERT_EMAIL');
        if ($email) {
            $from = Config::get('MAIL_FROM', 'noreply@localhost');
            @mail($email, '[' . Config::get('APP_NAME', 'NV Voter Platform') . '] ' . $subject, $body, "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8");
        }
        Log::info("ALERT {$subject}: " . str_replace("\n", ' / ', $body));
    }
}
