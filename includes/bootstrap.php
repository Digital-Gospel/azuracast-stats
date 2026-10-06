<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the AzuraCast Analytics Dashboard.
 *
 * Security principles:
 * - Secrets are loaded only from process environment variables or a private env file.
 * - The private env file must be supplied through AZURACAST_PROXY_ENV.
 * - No credentials are stored in source code.
 */

const MAX_ENV_FILE_SIZE = 65536;

/**
 * Load KEY=VALUE configuration from the private env file.
 *
 * Existing process environment variables always take precedence.
 *
 * @throws RuntimeException when the configured file is invalid or unreadable.
 */
function loadEnvFile(): void
{
    $envPath = getenv('AZURACAST_PROXY_ENV');
    if ($envPath === false || trim($envPath) === '') {
        return;
    }

    $envPath = trim($envPath);

    if (!is_file($envPath)) {
        throw new RuntimeException("Configured environment file does not exist: {$envPath}");
    }

    if (!is_readable($envPath)) {
        throw new RuntimeException("Configured environment file is not readable: {$envPath}");
    }

    $size = filesize($envPath);
    if ($size === false || $size > MAX_ENV_FILE_SIZE) {
        throw new RuntimeException('Environment file exceeds the 64 KiB safety limit.');
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Unable to read environment file: {$envPath}");
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
            continue;
        }

        $key = $matches[1];
        $value = trim($matches[2]);

        if (strlen($value) >= 2 &&
            (($value[0] === '"' && $value[-1] === '"') ||
             ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Read a configuration value.
 */
function cfg(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value !== false && $value !== '') {
        return $value;
    }

    return $_ENV[$key] ?? $default;
}

/**
 * Start a hardened PHP session.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

    session_name('AZCASTSESSID');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Send security headers suitable for JSON API responses.
 */
function sendSecurityHeaders(): void
{
    header_remove('X-Powered-By');

    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: application/json; charset=utf-8');
}

/**
 * Decode a JSON request body safely.
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}
