<?php

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Thin wrapper around PHP's native mail() for transactional email.
 *
 * From address: owner_email / owner_name from General settings.
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
        $this->fromEmail = $this->stripCrlf((string) $this->config->get('owner_email', ''));
        $this->fromName  = $this->stripCrlf((string) $this->config->get('owner_name',  ''));
    }

    /**
     * Sends a plain-text email.
     *
     * @throws RuntimeException if mail() fails.
     */
    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        $this->dispatch($toEmail, $toName, $subject, $body);
    }

    /**
     * Sends a plain-text email with an optional Reply-To header.
     *
     * @throws RuntimeException if mail() fails.
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

        $to      = $this->formatAddress($toName, $toEmail);
        $headers = $this->buildHeaders($replyEmail, $replyName);

        if (!mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $headers))) {
            throw new RuntimeException('mail() returned false — check server mail configuration.');
        }
    }

    /** @return list<string> */
    private function buildHeaders(string $replyEmail, string $replyName): array
    {
        $headers   = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        if ($this->fromEmail !== '') {
            $headers[] = 'From: ' . $this->formatAddress($this->fromName, $this->fromEmail);
        }

        if ($replyEmail !== '') {
            $headers[] = 'Reply-To: ' . $this->formatAddress($replyName, $replyEmail);
        }

        return $headers;
    }

    private function formatAddress(string $name, string $email): string
    {
        if ($name === '') {
            return $email;
        }

        // RFC 2047 encode display names with non-ASCII characters
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
        }

        // Quote display names that contain RFC 2822 special characters
        if (preg_match('/[",;:<>()@\\\\]/', $name)) {
            $name = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
        }

        return $name . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        $value = $this->stripCrlf($value);

        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function stripCrlf(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }
}
