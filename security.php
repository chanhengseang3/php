<?php
/**
 * security.php
 *
 * Centralized helpers for session bootstrapping, CSRF tokens, and
 * simple origin/referrer checks to guard form submissions.
 */

declare(strict_types=1);

/**
 * Start the session with secure cookie attributes (SameSite, HttpOnly, Secure).
 */
/**
 * Implement CSRF token generation
 * Store token in the session
 * Add hidden field with token to preference and login forms
 */
/**
 * Set SameSite attributes on cookies (Lax by default; uses Secure + HttpOnly)
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Fetch or generate a CSRF token stored in the session.
 */
function getCsrfToken(): string
{
    startSecureSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token.
 */
function validateCsrfToken(?string $submittedToken): bool
{
    startSecureSession();

    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submittedToken) || $submittedToken === '') {
        return false;
    }

    return is_string($sessionToken) && hash_equals($sessionToken, $submittedToken);
}

/**
 * Basic origin/referrer validation: allow only requests from the same host.
 *
 * @param array<int, string> $allowedHosts
 */
function validateRequestOrigin(array $allowedHosts): bool
{
    $allowedHosts = array_values(array_filter(array_unique($allowedHosts)));
    if (empty($allowedHosts)) {
        return true;
    }

    $originHost = '';
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $originHost = parse_url((string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) ?: '';
    }

    $refererHost = '';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $refererHost = parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST) ?: '';
    }

    if ($originHost && in_array($originHost, $allowedHosts, true)) {
        return true;
    }

    if ($refererHost && in_array($refererHost, $allowedHosts, true)) {
        return true;
    }

    // If no header is provided, treat as invalid to deter cross-site posts.
    return false;
}
