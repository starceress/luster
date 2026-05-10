<?php

declare(strict_types=1);

namespace Luster\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;

/**
 * Thin wrapper around PHPMailer for transactional email.
 *
 * From address resolution (first non-empty wins):
 *   1. smtp_from / smtp_from_name  — explicit SMTP sender override
 *   2. owner_email / owner_name    — site owner identity from General settings
 *
 * Delivery method:
 *   - smtp_host set  → PHPMailer sends via SMTP
 *   - smtp_host empty → PHPMailer uses PHP's native mail() function
 *
 * Usage:
 *   $mailer = new Mailer($app->getConfig());
 *   $mailer->send('fan@example.com', 'Fan Name', 'Joined!', $body);
 */
final class Mailer
{
    private bool   $useSMTP;
    private string $fromEmail;
    private string $fromName;

    public function __construct(private readonly Config $config)
    {
        $host = (string) $this->config->get('smtp_host', '');

        // Prefer the explicit SMTP From, fall back to the site owner identity
        $smtpFrom     = (string) $this->config->get('smtp_from',      '');
        $smtpFromName = (string) $this->config->get('smtp_from_name', '');
        $ownerEmail   = (string) $this->config->get('owner_email',    '');
        $ownerName    = (string) $this->config->get('owner_name',     '');

        $this->fromEmail = $smtpFrom     !== '' ? $smtpFrom     : $ownerEmail;
        $this->fromName  = $smtpFromName !== '' ? $smtpFromName : $ownerName;
        $this->useSMTP   = ($host !== '');
    }

    /**
     * Sends a plain-text email.
     *
     * @throws RuntimeException if PHPMailer reports an error.
     */
    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        $mail = $this->buildMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->isHTML(false);

        if (!$mail->send()) {
            throw new RuntimeException('Mailer error: ' . $mail->ErrorInfo);
        }
    }

    /**
     * Sends a plain-text email with an optional Reply-To header.
     *
     * Use this when the sender address belongs to the mail transport (SMTP/owner)
     * but replies should go to a different address (e.g. a feedback reporter).
     *
     * @throws RuntimeException if PHPMailer reports an error.
     */
    public function sendWithReplyTo(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        string $replyEmail = '',
        string $replyName  = '',
    ): void {
        $mail = $this->buildMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->isHTML(false);

        if ($replyEmail !== '') {
            $mail->addReplyTo($replyEmail, $replyName);
        }

        if (!$mail->send()) {
            throw new RuntimeException('Mailer error: ' . $mail->ErrorInfo);
        }
    }

    /**
     * Sends an HTML email (with a plain-text fallback).
     *
     * @throws RuntimeException if PHPMailer reports an error.
     */
    public function sendHtml(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $plainBody = '',
    ): void {
        $mail = $this->buildMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject  = $subject;
        $mail->isHTML(true);
        $mail->Body     = $htmlBody;
        $mail->AltBody  = $plainBody !== '' ? $plainBody : strip_tags($htmlBody);

        if (!$mail->send()) {
            throw new RuntimeException('Mailer error: ' . $mail->ErrorInfo);
        }
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function buildMailer(): PHPMailer
    {
        $mail = new PHPMailer(true); // exceptions enabled
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        if ($this->useSMTP) {
            $host   = (string) $this->config->get('smtp_host',   'localhost');
            $port   = (int)    $this->config->get('smtp_port',   587);
            $user   = (string) $this->config->get('smtp_user',   '');
            $pass   = (string) $this->config->get('smtp_pass',   '');
            $secure = (string) $this->config->get('smtp_secure', 'tls');

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = ($user !== '');
            $mail->Username   = $user;
            $mail->Password   = $pass;

            $mail->SMTPSecure = match ($secure) {
                'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
                'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
        } else {
            // No SMTP host — use PHP's native mail() function
            $mail->isMail();
        }

        if ($this->fromEmail !== '') {
            $mail->setFrom($this->fromEmail, $this->fromName);
        }

        return $mail;
    }
}
