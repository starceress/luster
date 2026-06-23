<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Transactional email delivery backed by PHPMailer. When SMTP is enabled in
 config it sends through the configured SMTP server; otherwise it falls back
 to PHP's built-in mail() function. Used by all email paths in the application
 including join confirmations, password resets, admin notifications, and
 bulk email operations.
*/

declare(strict_types=1);

namespace Luster\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use RuntimeException;

/**
 * Transactional email wrapper backed by PHPMailer.
 *
 * When smtp_enabled = '1' in config, sends via SMTP with the configured
 * credentials. Otherwise falls back to PHP's mail() via PHPMailer::isMail().
 *
 * Public API is unchanged from the previous mail() implementation —
 * all call sites remain unmodified.
 *
 * Usage:
 *   $mailer = new Mailer($app->getConfig());
 *   $mailer->send('fan@example.com', 'Fan Name', 'Joined!', $body);
 */
final class Mailer
{
    private string $fromEmail;
    private string $fromName;

    public function __construct(private readonly Config $config)
    {
        // From address: smtp_from first, falling back to owner_email.
        $this->fromEmail = $this->stripCrlf(
            (string) ($this->config->get('smtp_from', '') ?: $this->config->get('owner_email', ''))
        );
        $this->fromName = $this->stripCrlf(
            (string) ($this->config->get('smtp_from_name', '') ?: $this->config->get('owner_name', ''))
        );
    }

    /**
     * Sends a plain-text email.
     *
     * @throws RuntimeException if sending fails.
     */
    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        $this->dispatch($toEmail, $toName, $subject, $body);
    }

    /**
     * Sends a plain-text email with an optional Reply-To header.
     *
     * @throws RuntimeException if sending fails.
     */
    public function sendWithReplyTo(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        string $replyEmail = '',
        string $replyName  = '',
    ): void {
        $this->dispatch($toEmail, $toName, $subject, $body, $replyEmail, $replyName);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function dispatch(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        string $replyEmail = '',
        string $replyName  = '',
    ): void {
        $toEmail    = $this->stripCrlf($toEmail);
        $toName     = $this->stripCrlf($toName);
        $subject    = $this->stripCrlf($subject);
        $replyEmail = $this->stripCrlf($replyEmail);
        $replyName  = $this->stripCrlf($replyName);

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = '8bit';
            $mail->isHTML(false);

            if ($this->isSmtpEnabled()) {
                $this->configureSMTP($mail);
            } else {
                $mail->isMail();
            }

            if ($this->fromEmail !== '') {
                $mail->setFrom($this->fromEmail, $this->fromName);
            }

            $mail->addAddress($toEmail, $toName);

            if ($replyEmail !== '') {
                $mail->addReplyTo($replyEmail, $replyName);
            }

            $mail->Subject = $subject;
            $mail->Body    = $body;

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new RuntimeException('Email send failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function isSmtpEnabled(): bool
    {
        return (string) $this->config->get('smtp_enabled', '') === '1';
    }

    private function configureSMTP(PHPMailer $mail): void
    {
        $mail->isSMTP();
        $mail->Host     = (string) $this->config->get('smtp_host', '');
        $mail->Port     = (int)    $this->config->get('smtp_port', 587);
        $mail->Username = (string) $this->config->get('smtp_user', '');
        $mail->Password = (string) $this->config->get('smtp_pass', '');
        $mail->SMTPAuth = $mail->Username !== '' || $mail->Password !== '';

        $secure          = $this->resolveSmtpSecure((string) $this->config->get('smtp_secure', 'tls'));
        $mail->SMTPSecure = $secure;

        // When no encryption is requested, disable opportunistic STARTTLS too
        // so the "None" setting means truly unencrypted, not "try TLS if offered".
        if ($secure === '') {
            $mail->SMTPAutoTLS = false;
        }
    }

    private function resolveSmtpSecure(string $value): string
    {
        return match ($value) {
            'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => PHPMailer::ENCRYPTION_STARTTLS, // 'tls' or anything unrecognised
        };
    }

    private function stripCrlf(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }
}
