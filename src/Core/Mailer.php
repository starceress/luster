<?php

declare(strict_types=1);

namespace Luster\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;

/**
 * Thin wrapper around PHPMailer for transactional email.
 *
 * If smtp_host is empty in config, send() is a no-op (email disabled).
 * This lets the application run on local dev without SMTP configured.
 *
 * Usage:
 *   $mailer = new Mailer($app->getConfig());
 *   $mailer->send('fan@example.com', 'Fan Name', 'Joined!', $bodyHtml);
 */
final class Mailer
{
    private bool   $enabled;
    private string $fromEmail;
    private string $fromName;

    public function __construct(private readonly Config $config)
    {
        $host            = (string) $this->config->get('smtp_host', '');
        $this->enabled   = ($host !== '');
        $this->fromEmail = (string) $this->config->get('smtp_from',      '');
        $this->fromName  = (string) $this->config->get('smtp_from_name', 'Luster');
    }

    /**
     * Sends a plain-text email.
     *
     * @throws RuntimeException if PHPMailer reports an error.
     */
    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        if (!$this->enabled) {
            return;
        }

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
        if (!$this->enabled) {
            return;
        }

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

        $host    = (string) $this->config->get('smtp_host',   'localhost');
        $port    = (int)    $this->config->get('smtp_port',   587);
        $user    = (string) $this->config->get('smtp_user',   '');
        $pass    = (string) $this->config->get('smtp_pass',   '');
        $secure  = (string) $this->config->get('smtp_secure', 'tls');

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

        $mail->setFrom($this->fromEmail, $this->fromName);

        return $mail;
    }
}
