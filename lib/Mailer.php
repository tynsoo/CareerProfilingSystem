<?php

require_once __DIR__ . '/../config/env.php';

/**
 * Sends transactional email via Brevo's HTTPS REST API rather than raw SMTP.
 * Render's free web service tier blocks outbound SMTP ports (25/587/465)
 * entirely — PHPMailer/SMTP connections there fail with ETIMEDOUT no matter
 * how the credentials are set up. HTTPS (443) isn't blocked, so this posts
 * to Brevo's API endpoint instead of opening an SMTP socket.
 *
 * When BREVO_API_KEY isn't set (local dev has no real Brevo account),
 * sending is skipped and the message is written to the PHP error log
 * instead, so the reset/notification flow stays testable without real
 * credentials.
 */
class Mailer
{
    public static function send(string $toEmail, string $toName, string $subject, string $bodyHtml, string $bodyText): bool
    {
        $apiKey = getenv('BREVO_API_KEY');
        if (!$apiKey) {
            error_log("[Mailer] BREVO_API_KEY not configured — logging instead of sending.\nTo: $toName <$toEmail>\nSubject: $subject\n\n$bodyText");
            return false;
        }

        $payload = json_encode([
            'sender' => ['name' => getenv('SMTP_FROM_NAME') ?: 'ProfilePath', 'email' => getenv('SMTP_FROM_EMAIL')],
            'to' => [['email' => $toEmail, 'name' => $toName]],
            'subject' => $subject,
            'htmlContent' => $bodyHtml,
            'textContent' => $bodyText,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\napi-key: $apiKey\r\n",
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
                break;
            }
        }

        if ($status >= 200 && $status < 300) {
            return true;
        }

        error_log("[Mailer] Brevo API send failed (HTTP $status): " . ($result !== false ? $result : 'no response — request may not have reached Brevo'));
        return false;
    }
}
