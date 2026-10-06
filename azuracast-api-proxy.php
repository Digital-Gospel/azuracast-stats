<?php
declare(strict_types=1);

/**
 * Read-only AzuraCast API proxy for the Analytics Dashboard.
 *
 * The browser never receives the AzuraCast API key.
 *
 * Security controls:
 * - GET-only request handling.
 * - Strict endpoint allowlist.
 * - Server-side API credentials only.
 * - Per-IP rate limiting.
 * - HTTPS-only upstream validation.
 * - TLS certificate/hostname verification.
 * - Security response headers.
 * - Generic client errors with internal details kept in logs.
 * - Listener privacy filtering: raw IP, user-agent and listener hash are removed.
 */

require_once __DIR__ . '/includes/bootstrap.php';

set_time_limit(20);
ignore_user_abort(false);
error_reporting(0);

sendSecurityHeaders();

try {
    loadEnvFile();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(
        ['error' => 'Server configuration error.', 'status' => 500],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

startSecureSession();

/**
 * Log levels.
 */
const LOG_OFF = 0;
const LOG_STANDARD = 1;
const LOG_DEBUG = 2;

$_logLevelConfig = strtolower(cfg('LOG_LEVEL', 'standard'));
if ($_logLevelConfig === 'debug') {
    $logLevel = LOG_DEBUG;
} elseif ($_logLevelConfig === 'off') {
    $logLevel = LOG_OFF;
} else {
    $logLevel = LOG_STANDARD;
}
unset($_logLevelConfig);

/**
 * Write a proxy log entry.
 */
function proxyLog(string $severity, string $message, array $context = []): void
{
    global $logLevel;

    if ($logLevel === LOG_OFF) {
        return;
    }

    if ($severity === 'DEBUG' && $logLevel < LOG_DEBUG) {
        return;
    }

    $logFile = cfg('LOG_FILE', '');
    if ($logFile === '') {
        return;
    }

    $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $method = $_SERVER['REQUEST_METHOD'] ?? '-';
    $uri = $_SERVER['REQUEST_URI'] ?? '-';

    $contextText = '';
    if ($context !== []) {
        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . (is_string($value) ? $value : json_encode($value));
        }
        $contextText = ' [' . implode(' ', $parts) . ']';
    }

    $line = "[{$timestamp}] [{$severity}] {$ip} \"{$method} {$uri}\" {$message}{$contextText}" . PHP_EOL;

    $directory = dirname($logFile);
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Return a JSON error and terminate the request.
 */
function jsonError(string $publicMessage, int $status = 500, string $internalMessage = ''): void
{
    $severity = $status >= 500 ? 'ERROR' : ($status === 403 ? 'WARN' : 'INFO');

    proxyLog(
        $severity,
        "HTTP {$status}: {$publicMessage}" .
        ($internalMessage !== '' ? " | internal: {$internalMessage}" : '')
    );

    http_response_code($status);

    echo json_encode(
        ['error' => $publicMessage, 'status' => $status],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * Check whether the current IP exceeded the configured request rate.
 */
function isRateLimited(): bool
{
    $requestsPerMinute = (int) cfg('RATE_LIMIT_RPM', '120');
    if ($requestsPerMinute <= 0) {
        return false;
    }

    $directory = cfg(
        'RATE_LIMIT_DIR',
        sys_get_temp_dir() . '/azuracast-analytics-rate-limit'
    );

    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $safeIp = preg_replace('/[^a-fA-F0-9:._-]/', '_', $ip) ?? 'unknown';
    $file = $directory . '/rl_' . $safeIp . '.json';

    $now = time();
    $window = 60;

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        // Fail open if the filesystem is unavailable; log the problem.
        proxyLog('WARN', 'Rate limiter state file could not be opened', ['file' => $file]);
        return false;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        proxyLog('WARN', 'Rate limiter state file could not be locked', ['file' => $file]);
        return false;
    }

    $content = stream_get_contents($handle);
    $data = json_decode($content ?: '{}', true);
    $hits = is_array($data['hits'] ?? null) ? $data['hits'] : [];

    $hits = array_values(array_filter(
        $hits,
        static fn ($timestamp): bool =>
            is_numeric($timestamp) && (int) $timestamp > $now - $window
    ));

    $hits[] = $now;
    $exceeded = count($hits) > $requestsPerMinute;

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode(['hits' => $hits]));
    fflush($handle);

    flock($handle, LOCK_UN);
    fclose($handle);

    return $exceeded;
}

/**
 * Handle optional exact-match CORS.
 */
function handleCors(): void
{
    $allowedOrigin = trim(cfg('ALLOWED_ORIGIN', ''));

    if ($allowedOrigin === '') {
        return;
    }

    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($requestOrigin !== $allowedOrigin) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Accept');
    header('Vary: Origin');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Validate the Turnstile-backed session.
 */
function requireTurnstileVerification(): void
{
    $verifiedAt = (int) ($_SESSION['cf_verified_at'] ?? 0);
    $ttl = max(60, (int) cfg('TURNSTILE_SESSION_TTL', '3600'));

    if (
        empty($_SESSION['cf_verified']) ||
        $verifiedAt <= 0 ||
        time() - $verifiedAt > $ttl
    ) {
        unset($_SESSION['cf_verified'], $_SESSION['cf_verified_at']);

        http_response_code(403);
        echo json_encode(
            ['error' => 'Security verification required.', 'status' => 403],
            JSON_UNESCAPED_SLASHES
        );

        proxyLog('WARN', 'Turnstile verification missing or expired');
        exit;
    }
}

/**
 * Remove credentials/control characters before putting the API key into a header.
 */
function sanitizeApiKey(string $key): string
{
    $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $key);

    if ($clean === null || $clean !== $key) {
        proxyLog('WARN', 'API key contained control characters; they were removed');
    }

    return $clean ?? '';
}

/**
 * Strip listener identifiers and raw network/browser fingerprints from the response.
 *
 * The dashboard does not need these fields. Removing them at the proxy prevents
 * them from reaching the browser and reduces privacy exposure.
 */
function sanitizeListenersPayload(string $body): string
{
    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        return $body;
    }

    if (isset($decoded['listeners']) && is_array($decoded['listeners'])) {
        $decoded['listeners'] = array_map('sanitizeListenerItem', $decoded['listeners']);
    } elseif (isset($decoded['results']) && is_array($decoded['results'])) {
        $decoded['results'] = array_map('sanitizeListenerItem', $decoded['results']);
    } elseif (isSequentialArray($decoded)) {
        $decoded = array_map('sanitizeListenerItem', $decoded);
    }

    $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

/**
 * Strip direct identifiers from one listener item.
 */
function isSequentialArray(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

/**
 * Strip direct identifiers from one listener item.
 */
function sanitizeListenerItem(array $item): array
{
    unset(
        $item['ip'],
        $item['remote_ip'],
        $item['remoteIp'],
        $item['user_agent'],
        $item['hash']
    );

    return $item;
}

handleCors();
requireTurnstileVerification();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    jsonError('Only GET requests are allowed.', 405);
}

if (isRateLimited()) {
    proxyLog('WARN', 'Rate limit exceeded', [
        'rpm' => cfg('RATE_LIMIT_RPM', '120'),
    ]);

    header('Retry-After: 60');
    jsonError('Too many requests. Please try again later.', 429);
}

$baseUrl = rtrim(cfg('AZURACAST_BASE_URL'), '/');
$stationId = (int) cfg('AZURACAST_STATION_ID', '1');
$apiKey = sanitizeApiKey(cfg('AZURACAST_API_KEY', ''));
$connectTimeout = max(1, (int) cfg('CURL_CONNECT_TIMEOUT', '5'));
$timeout = max(1, (int) cfg('CURL_TIMEOUT', '15'));

if ($baseUrl === '') {
    jsonError('Server configuration error.', 500, 'AZURACAST_BASE_URL is not set.');
}

if ($stationId <= 0) {
    jsonError('Server configuration error.', 500, 'AZURACAST_STATION_ID must be a positive integer.');
}

if ($apiKey === '') {
    jsonError('Server configuration error.', 500, 'AZURACAST_API_KEY is not set.');
}

if (!preg_match('#^https://#i', $baseUrl)) {
    jsonError('Server configuration error.', 500, 'AZURACAST_BASE_URL must use HTTPS.');
}

$rawPath = isset($_GET['path']) ? rawurldecode((string) $_GET['path']) : '';
$path = '/' . ltrim($rawPath, '/');

$allowed = [
    "/station/{$stationId}",
    "/station/{$stationId}/nowplaying",
    "/station/{$stationId}/reports/overview/charts",
    "/station/{$stationId}/reports/overview/best-and-worst",
    "/station/{$stationId}/history",
    "/station/{$stationId}/listeners",
];

if (!in_array($path, $allowed, true)) {
    proxyLog('WARN', 'Endpoint rejected', [
        'path' => $path,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '-',
    ]);

    jsonError('Endpoint is not allowed.', 403);
}

proxyLog('DEBUG', 'Request accepted', [
    'path' => $path,
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 80),
]);

$upstreamUrl = $baseUrl . $path;

if (!function_exists('curl_init')) {
    jsonError('The PHP cURL extension is required.', 500, 'curl_init() is not available.');
}

$curl = curl_init($upstreamUrl);
if ($curl === false) {
    jsonError('Internal server error.', 500, 'curl_init() returned false.');
}

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'X-API-Key: ' . $apiKey,
        'User-Agent: AzuraCast-Analytics-Dashboard/2.3',
    ],
    CURLOPT_COOKIE => '',
]);

$start = microtime(true);
$body = curl_exec($curl);
$elapsedMs = round((microtime(true) - $start) * 1000);

$curlErrno = curl_errno($curl);
$curlError = curl_error($curl);
$httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

curl_close($curl);

if ($body === false || $curlErrno !== 0) {
    proxyLog('ERROR', 'cURL request failed', [
        'errno' => $curlErrno,
        'error' => $curlError,
        'path' => $path,
        'time_ms' => $elapsedMs,
    ]);

    jsonError('Unable to connect to the AzuraCast server.', 502);
}

proxyLog('DEBUG', 'Upstream response received', [
    'path' => $path,
    'http_status' => $httpStatus,
    'content_type' => strtok($contentType, ';'),
    'body_bytes' => strlen((string) $body),
    'time_ms' => $elapsedMs,
]);

if ($httpStatus < 200 || $httpStatus >= 300) {
    $safeStatus = ($httpStatus >= 400 && $httpStatus <= 599) ? $httpStatus : 502;

    proxyLog('ERROR', 'Upstream returned an HTTP error', [
        'upstream_status' => $httpStatus,
        'path' => $path,
        'time_ms' => $elapsedMs,
    ]);

    jsonError(
        "The AzuraCast server returned an error (HTTP {$httpStatus}).",
        $safeStatus
    );
}

if ($path === "/station/{$stationId}/listeners") {
    $body = sanitizeListenersPayload((string) $body);
}

proxyLog('INFO', 'Request completed successfully', [
    'path' => $path,
    'status' => $httpStatus,
    'time_ms' => $elapsedMs,
]);

echo $body;
