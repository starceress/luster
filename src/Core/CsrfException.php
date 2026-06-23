<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Exception thrown by CsrfMiddleware when a POST request arrives without a
 valid CSRF token. The application's exception handler catches this and
 sends a 403 Forbidden response to the browser.
*/

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Thrown by CsrfMiddleware when a POST request arrives without a valid CSRF token.
 * Application::handleException() catches this and sends a 403 Forbidden response.
 */
final class CsrfException extends RuntimeException {}
