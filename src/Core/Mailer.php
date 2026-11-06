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
    /**
     * How long to wait for most steps with a mail server, in seconds. PHPMailer's
     * default is 5 minutes, and Luster sends email while someone waits for a page
     * to load, so a dead mail server made the page look frozen. This limits the
     * waits, not the whole send, because PHPMailer tries more commands after a stall.
     * In testing, a server that couldn't be reached took 15 seconds, and one that
     * went quiet partway through took up to 60.
     */
    private const SMTP_WAIT_SECONDS = 15;

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
     * @param string[] $attachmentPaths Absolute paths to files to attach. Missing or
     *                                  unreadable paths are silently skipped rather
     *                                  than failing the whole send.
     * @throws RuntimeException if sending fails.
     */
    public function send(string $toEmail, string $toName, string $subject, string $body, array $attachmentPaths = []): void
    {
        $this->dispatch($toEmail, $toName, $subject, $body, '', '', $attachmentPaths);
    }

    /**
     * Sends a plain-text email with an optional Reply-To header.
     *
     * @param string[] $attachmentPaths Absolute paths to files to attach. Missing or
     *                                  unreadable paths are silently skipped rather
     *                                  than failing the whole send.
     * @throws RuntimeException if sending fails.
     */
    public function sendWithReplyTo(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        string $replyEmail = '',
        string $replyName  = '',
        array  $attachmentPaths = [],
    ): void {
        $this->dispatch($toEmail, $toName, $subject, $body, $replyEmail, $replyName, $attachmentPaths);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param string[] $attachmentPaths
     */
    private function dispatch(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        string $replyEmail = '',
        string $replyName  = '',
        array  $attachmentPaths = [],
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

            foreach ($this->filterValidAttachments($attachmentPaths) as $path) {
                $mail->addAttachment($path);
            }

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

        // PHPMailer has two separate waits, and both need lowering. Timeout covers
        // connecting. Timelimit covers waiting for a reply, so with only Timeout
        // lowered, a server that connects and then goes quiet still takes 5 minutes.
        // PHPMailer doubles Timelimit for the reply to the message itself, which gives
        // a mail server that scans messages before accepting them a little longer.
        $mail->Timeout = self::SMTP_WAIT_SECONDS;
        $mail->getSMTPInstance()->Timelimit = self::SMTP_WAIT_SECONDS;

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

    /**
     * Filters an attachment path list down to ones that actually exist and are
     * readable, so a missing or invalid path never breaks the whole send.
     *
     * @param string[] $paths
     * @return string[]
     */
    private function filterValidAttachments(array $paths): array
    {
        return array_values(array_filter(
            $paths,
            static fn(mixed $path): bool => is_string($path) && $path !== '' && is_file($path) && is_readable($path),
        ));
    }
}
