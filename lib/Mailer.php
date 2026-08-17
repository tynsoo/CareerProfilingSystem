<?php

require_once __DIR__ . '/../config/env.php';

/**
 * Thin wrapper around the vendored PHPMailer + Brevo SMTP. When SMTP_USERNAME/
 * SMTP_PASSWORD aren't set (local dev has no real Brevo account), sending is
 * skipped and the message is written to the PHP error log instead, so the
 * reset/notification flow stays testable without real email credentials.
 */
class Mailer
{
    public static function send(string $toEmail, string $toName, string $subject, string $bodyHtml, string $bodyText): bool
    {
        $username = getenv('SMTP_USERNAME');
        $password = getenv('SMTP_PASSWORD');

        if (!$username || !$password) {
            error_log("[Mailer] SMTP not configured — logging instead of sending.\nTo: $toName <$toEmail>\nSubject: $subject\n\n$bodyText");
            return false;
        }

        require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
        require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) getenv('SMTP_PORT');

            $mail->setFrom(getenv('SMTP_FROM_EMAIL'), getenv('SMTP_FROM_NAME'));
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $bodyHtml;
            $mail->AltBody = $bodyText;

            $mail->send();
            return true;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            error_log('[Mailer] send failed: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
