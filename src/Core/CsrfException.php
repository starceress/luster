<?php

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Thrown by CsrfMiddleware when a POST request arrives without a valid CSRF token.
 * Application::handleException() catches this and sends a 403 Forbidden response.
 */
final class CsrfException extends RuntimeException {}
