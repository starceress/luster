<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 HTTP response builder returned by every controller action. Provides static
 factory methods for common response types (HTML, JSON, redirect, error
 pages) and a send() method that emits headers and body. The front
 controllers call send() after Application::run() returns -- no controller
 or core code calls exit() directly.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * HTTP response builder.
 *
 * Controllers return a Response object; Application::run() calls send().
 * Nothing in Core or application code calls exit() — that is left to the
 * front controllers (index.php / admin.php) after run() returns.
 *
 * Static factory methods make common patterns concise:
 *
 *   return Response::html($view->render('join.html', $data));
 *   return Response::redirect('/thank-you');
 *   return Response::json(['success' => true]);
 *   return Response::notFound();
 */
final class Response
{
    private int     $status      = 200;
    private array   $headers     = [];
    private string  $body        = '';
    private ?string $redirectUrl = null;

    // -----------------------------------------------------------------------
    // Static factories
    // -----------------------------------------------------------------------

    /** Creates an HTML response with Content-Type: text/html; charset=UTF-8. */
    public static function html(string $content, int $status = 200): static
    {
        return (new static())
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($content);
    }

    /** Creates a JSON response. $data is encoded with JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE. */
    public static function json(mixed $data, int $status = 200): static
    {
        $encoded = (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return (new static())
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withBody($encoded);
    }

    /** Creates a redirect response. Always use a full URL (with scheme) for Location. */
    public static function redirect(string $url, int $code = 302): static
    {
        $r              = new static();
        $r->redirectUrl = $url;
        $r->status      = $code;

        return $r;
    }

    /** Creates a 404 Not Found HTML response with the given message. */
    public static function notFound(string $message = 'The page you requested was not found.'): static
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return static::html(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<title>404 Not Found</title></head><body>'
            . '<h1>404 Not Found</h1><p>' . $safe . '</p>'
            . '</body></html>',
            404
        );
    }

    /** Creates a CSV download response. The filename is sanitised to alphanumeric, dots, dashes, and underscores. */
    public static function csv(string $content, string $filename = 'export.csv'): static
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $filename);

        return (new static())
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withBody($content);
    }

    /** Creates a binary file download response with Content-Disposition: attachment. */
    public static function download(string $body, string $filename, string $mime = 'application/octet-stream'): static
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $filename);

        return (new static())
            ->withStatus(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withBody($body);
    }

    /** Creates a 500 Internal Server Error HTML response. */
    public static function serverError(string $message = 'An unexpected error occurred.'): static
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return static::html(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<title>500 Internal Server Error</title></head><body>'
            . '<h1>Internal Server Error</h1><p>' . $safe . '</p>'
            . '</body></html>',
            500
        );
    }

    // -----------------------------------------------------------------------
    // Builder methods (clone-based, safe for immutable usage patterns)
    // -----------------------------------------------------------------------

    /** Returns a clone of this response with the given HTTP status code. */
    public function withStatus(int $code): static
    {
        $clone         = clone $this;
        $clone->status = $code;

        return $clone;
    }

    /** Returns a clone of this response with an additional response header. */
    public function withHeader(string $name, string $value): static
    {
        $clone                  = clone $this;
        $clone->headers[$name]  = $value;

        return $clone;
    }

    /** Returns a clone of this response with the given body string. */
    public function withBody(string $body): static
    {
        $clone       = clone $this;
        $clone->body = $body;

        return $clone;
    }

    // -----------------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------------

    /**
     * Sends headers and body to the client.
     * Does NOT call exit() — that is the front controller's responsibility.
     */
    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->redirectUrl !== null) {
            header('Location: ' . $this->redirectUrl);

            return;
        }

        echo $this->body;
    }

    // -----------------------------------------------------------------------
    // Inspection
    // -----------------------------------------------------------------------

    /** Returns the HTTP status code. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Returns true when this response will emit a Location header. */
    public function isRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }

    /** Returns the redirect target URL, or null for non-redirect responses. */
    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    /** Returns the response body string. */
    public function getBody(): string
    {
        return $this->body;
    }
}
