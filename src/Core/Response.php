<?php

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

    public static function html(string $content, int $status = 200): static
    {
        return (new static())
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($content);
    }

    public static function json(mixed $data, int $status = 200): static
    {
        $encoded = (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return (new static())
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withBody($encoded);
    }

    public static function redirect(string $url, int $code = 302): static
    {
        $r              = new static();
        $r->redirectUrl = $url;
        $r->status      = $code;

        return $r;
    }

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

    public function withStatus(int $code): static
    {
        $clone         = clone $this;
        $clone->status = $code;

        return $clone;
    }

    public function withHeader(string $name, string $value): static
    {
        $clone                  = clone $this;
        $clone->headers[$name]  = $value;

        return $clone;
    }

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

    public function getStatus(): int
    {
        return $this->status;
    }

    public function isRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
