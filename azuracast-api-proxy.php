<?php
declare(strict_types=1);

/**
 * Read-only AzuraCast API Proxy — Live Radio Analytics Dashboard
 *
 * Architektura bezpieczeństwa:
 *  - Cała konfiguracja (klucz API, URL, station_id, timeouty) pochodzi z pliku .env
 *    lub zmiennych środowiskowych — ZERO hardcoded secrets w kodzie PHP.
 *  - Wyłącznie metoda GET; whitelist endpointów (strict match).
 *  - Rate limiting per IP oparty na plikach tymczasowych.
 *  - Logowanie w trybie standard i debug z maskowanym kluczem API.
 *  - Nagłówki bezpieczeństwa HTTP (CSP, HSTS, nosniff, frame-ancestors).
 *  - Jawna weryfikacja TLS (SSL_VERIFYPEER, SSL_VERIFYHOST).
 *  - Sanitizacja klucza API przed wstrzyknięciem do nagłówka cURL.
 *  - Szczegóły błędów sieciowych logowane wewnętrznie, nie ujawniane klientowi.
 *
 * Wymagane zmienne środowiskowe (lub plik .env wskazany przez AZURACAST_PROXY_ENV):
 *   AZURACAST_API_KEY        – klucz API (tylko do odczytu)
 *   AZURACAST_BASE_URL       – bazowy URL API bez trailing slash
 *   AZURACAST_STATION_ID     – ID stacji (liczba całkowita)
 *   LOG_LEVEL                – standard | debug | off
 *   LOG_FILE                 – ścieżka do pliku logu
 *   RATE_LIMIT_RPM           – max requestów/IP/minutę (0 = wyłączony)
 *   RATE_LIMIT_DIR           – katalog na stan rate limitera
 *   CURL_CONNECT_TIMEOUT     – timeout TCP connect (s)
 *   CURL_TIMEOUT             – całkowity timeout cURL (s)
 *   ALLOWED_ORIGIN           – dozwolony Origin CORS (opcjonalny)
 */

// ─────────────────────────────────────────────────────────────
// 0. BOOTSTRAP
// ─────────────────────────────────────────────────────────────

set_time_limit(20);                // PHP-level safety net
ignore_user_abort(false);          // nie kontynuuj gdy klient się rozłączy

const SECRET_FILE = './azuracast-api-proxy.env';

// ─────────────────────────────────────────────────────────────
// 1. KONFIGURACJA — wczytana wyłącznie z .env / zmiennych środowiskowych
// ─────────────────────────────────────────────────────────────

/**
 * Wczytuje zmienne z pliku .env (format KEY=VALUE, komentarze #, cudzysłowy opcjonalne).
 * Plik .env wskazywany przez zmienną środowiskową AZURACAST_PROXY_ENV.
 * Istniejące zmienne środowiskowe mają WYŻSZY priorytet niż plik .env.
 *
 * @throws RuntimeException gdy plik jest zbyt duży lub nieczytelny
 */
function loadEnvFile(): void
{
    if (file_exists(SECRET_FILE)) {
        $envPath = SECRET_FILE;
    } else {
        $envPath = getenv('AZURACAST_PROXY_ENV');
        if ($envPath === false || trim($envPath) === '') {
            return; // brak pliku — polegamy wyłącznie na zmiennych środowiskowych
        }
    }

    $envPath = trim($envPath);

    if (!file_exists($envPath)) {
        throw new RuntimeException("Plik .env nie istnieje: {$envPath}");
    }

    if (!is_readable($envPath)) {
        throw new RuntimeException("Plik .env jest nieczytelny: {$envPath}");
    }

    // Ochrona przed bardzo dużymi plikami (max 64 KB)
    $size = filesize($envPath);
    if ($size === false || $size > 65536) {
        throw new RuntimeException('Plik .env jest zbyt duży (limit 64 KB).');
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Nie można odczytać pliku .env: {$envPath}");
    }

    foreach ($lines as $lineNo => $line) {
        $line = trim($line);

        // Pomiń komentarze i puste linie
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Format: KEY=VALUE (VALUE opcjonalnie w cudzysłowach)
        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) {
            continue; // nieprawidłowa linia — ignoruj zamiast przerywać
        }

        $key   = $m[1];
        $value = trim($m[2]);

        // Usuń opcjonalne cudzysłowy
        if (strlen($value) >= 2
            && (($value[0] === '"'  && $value[-1] === '"')
             || ($value[0] === "'"  && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        // Istniejące zmienne środowiskowe mają wyższy priorytet
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Pobiera zmienną konfiguracyjną (env → $_ENV → default).
 */
function cfg(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    return $_ENV[$key] ?? $default;
}

// ─────────────────────────────────────────────────────────────
// 2. LOGOWANIE
// ─────────────────────────────────────────────────────────────

/**
 * Poziomy logowania.
 */
const LOG_OFF      = 0;
const LOG_STANDARD = 1;
const LOG_DEBUG    = 2;

$logLevel = LOG_STANDARD; // zostanie nadpisany po załadowaniu .env

/**
 * Zapisuje wpis do logu.
 *
 * @param string $severity  ERROR | WARN | INFO | DEBUG
 * @param string $message   Treść logu
 * @param array  $context   Dodatkowe dane (klucze => wartości)
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
        return; // logowanie wyłączone gdy brak ścieżki
    }

    $ts      = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '–';
    $method  = $_SERVER['REQUEST_METHOD'] ?? '–';
    $uri     = $_SERVER['REQUEST_URI'] ?? '–';

    $ctxStr = '';
    if (!empty($context)) {
        $parts = [];
        foreach ($context as $k => $v) {
            $parts[] = "{$k}=" . (is_string($v) ? $v : json_encode($v));
        }
        $ctxStr = ' [' . implode(' ', $parts) . ']';
    }

    $line = "[{$ts}] [{$severity}] {$ip} \"{$method} {$uri}\" {$message}{$ctxStr}" . PHP_EOL;

    // Użyj file_put_contents z LOCK_EX — bezpieczne przy równoległych requestach
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ─────────────────────────────────────────────────────────────
// 3. OBSŁUGA BŁĘDÓW PROXY
// ─────────────────────────────────────────────────────────────

/**
 * Wysyła odpowiedź JSON z błędem, loguje i kończy skrypt.
 *
 * @param string $public   Komunikat dla klienta (nie ujawnia szczegółów wewnętrznych)
 * @param int    $status   HTTP status code
 * @param string $internal Szczegóły do loga (nie trafiają do klienta)
 */
function jsonError(string $public, int $status = 500, string $internal = ''): void
{
    if ($status >= 500) {
        $severity = 'ERROR';
    } elseif ($status === 403) {
        $severity = 'WARN';
    } else {
        $severity = 'INFO';
    }

    proxyLog($severity, "HTTP {$status}: {$public}" . ($internal !== '' ? " | internal: {$internal}" : ''));

    http_response_code($status);
    echo json_encode(
        ['error' => $public, 'status' => $status],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. RATE LIMITING — prosty per-IP, oparty na plikach tymczasowych
// ─────────────────────────────────────────────────────────────

/**
 * Sprawdza limit requestów per IP (okno 60 sekund).
 * Używa pliku blokującego (flock) zamiast bazy danych.
 *
 * @return bool true = limit przekroczony
 */
function isRateLimited(): bool
{
    $rpm = (int) cfg('RATE_LIMIT_RPM', '60');
    if ($rpm <= 0) {
        return false; // rate limiting wyłączony
    }

    $dir = cfg('RATE_LIMIT_DIR', sys_get_temp_dir() . '/azuracast-proxy-rl');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $safeIp  = preg_replace('/[^a-fA-F0-9:.\_-]/', '_', $ip);
    $file    = $dir . '/rl_' . $safeIp . '.json';
    $now     = time();
    $window  = 60; // sekundy

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        proxyLog('WARN', 'Rate limiter: nie można otworzyć pliku stanu', ['file' => $file]);
        return false; // fail open — lepsze niż blokowanie przy błędach FS
    }

    flock($fh, LOCK_EX);

    $data    = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    $hits    = is_array($data['hits'] ?? null) ? $data['hits'] : [];

    // Usuń wpisy spoza okna czasowego
    $hits = array_filter($hits, static fn(int $t) => $t > $now - $window);
    $hits[] = $now;

    $exceeded = count($hits) > $rpm;

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode(['hits' => array_values($hits)]));
    flock($fh, LOCK_UN);
    fclose($fh);

    return $exceeded;
}

// ─────────────────────────────────────────────────────────────
// 5. NAGŁÓWKI BEZPIECZEŃSTWA HTTP
// ─────────────────────────────────────────────────────────────

function sendSecurityHeaders(): void
{
    // Nie ujawniaj wersji PHP
    header_remove('X-Powered-By');

    // Blokuj osadzanie w ramkach (tylko same-origin)
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");

    // Chroń przed MIME sniffingiem
    header('X-Content-Type-Options: nosniff');

    // Ogranicz referrer
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // HSTS — wymuszaj HTTPS przez rok (tylko gdy jesteś na HTTPS)
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Ogranicz dostęp do czułych API przeglądarki
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

    // Cache: odpowiedzi proxy nie powinny być cachowane
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Content-Type zawsze JSON
    header('Content-Type: application/json; charset=utf-8');
}

// ─────────────────────────────────────────────────────────────
// 6. CORS — opcjonalnie, tylko gdy skonfigurowany ALLOWED_ORIGIN
// ─────────────────────────────────────────────────────────────

function handleCors(): void
{
    $allowedOrigin = cfg('ALLOWED_ORIGIN', '');
    if ($allowedOrigin === '') {
        return; // CORS wyłączony — same-origin only
    }

    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Porównanie exact match (nie wildcard, nie contains)
    if ($requestOrigin !== $allowedOrigin) {
        return; // nieznany origin — nie dodaj nagłówka CORS (przeglądarka zablokuje)
    }

    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Accept');
    header('Vary: Origin');

    // Preflight OPTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────
// 7. SANITIZACJA KLUCZA API (ochrona przed cURL header injection)
// ─────────────────────────────────────────────────────────────

/**
 * Usuwa wszystkie znaki sterujące (w tym CR, LF) z klucza API.
 * Zapobiega header injection gdyby klucz zawierał \r\n.
 */
function sanitizeApiKey(string $key): string
{
    // Usuń wszystkie bajty < 0x20 i 0x7F
    $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $key);
    if ($clean === null || $clean !== $key) {
        proxyLog('WARN', 'Klucz API zawierał niedozwolone znaki sterujące — zostały usunięte');
    }
    return $clean ?? '';
}

// ─────────────────────────────────────────────────────────────
// 8. ŁADOWANIE KONFIGURACJI
// ─────────────────────────────────────────────────────────────

// Najpierw nagłówki (zawsze, nawet przed błędem konfiguracji)
sendSecurityHeaders();
handleCors();

// Załaduj .env
try {
    loadEnvFile();
} catch (RuntimeException $e) {
    jsonError('Błąd konfiguracji serwera.', 500, $e->getMessage());
}

// Ustaw poziom logowania po załadowaniu .env
$_logLevelCfg = cfg('LOG_LEVEL', 'standard');
if ($_logLevelCfg === 'debug') {
    $logLevel = LOG_DEBUG;
} elseif ($_logLevelCfg === 'off') {
    $logLevel = LOG_OFF;
} else {
    $logLevel = LOG_STANDARD;
}
unset($_logLevelCfg);

// Odczytaj konfigurację
$baseUrl   = rtrim(cfg('AZURACAST_BASE_URL'), '/');
$stationId = (int) cfg('AZURACAST_STATION_ID', '1');
$apiKey    = sanitizeApiKey(cfg('AZURACAST_API_KEY', ''));
$curlConnectTimeout = (int) cfg('CURL_CONNECT_TIMEOUT', '5');
$curlTimeout        = (int) cfg('CURL_TIMEOUT', '15');

// Walidacja wymaganych wartości
if ($baseUrl === '') {
    jsonError('Błąd konfiguracji serwera.', 500, 'AZURACAST_BASE_URL nie jest ustawiony.');
}
if ($stationId <= 0) {
    jsonError('Błąd konfiguracji serwera.', 500, 'AZURACAST_STATION_ID nieprawidłowy: ' . cfg('AZURACAST_STATION_ID'));
}
if ($apiKey === '') {
    jsonError('Błąd konfiguracji serwera.', 500, 'AZURACAST_API_KEY nie jest ustawiony.');
}

// Walidacja URL — musi być https:// (nie http://)
if (!preg_match('#^https://#', $baseUrl)) {
    jsonError('Błąd konfiguracji serwera.', 500, 'AZURACAST_BASE_URL musi używać HTTPS: ' . $baseUrl);
}

// ─────────────────────────────────────────────────────────────
// 9. WALIDACJA ŻĄDANIA
// ─────────────────────────────────────────────────────────────

// Tylko GET
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    jsonError('Dozwolone jest wyłącznie żądanie GET.', 405);
}

// Rate limiting
if (isRateLimited()) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '–';
    proxyLog('WARN', 'Rate limit przekroczony', [
        'ip'  => $ip,
        'rpm' => cfg('RATE_LIMIT_RPM', '60'),
    ]);
    header('Retry-After: 60');
    jsonError('Zbyt wiele żądań. Spróbuj za chwilę.', 429);
}

// Walidacja i normalizacja ścieżki
$rawPath = isset($_GET['path']) ? rawurldecode((string) $_GET['path']) : '';
$path    = '/' . ltrim($rawPath, '/');

// Whitelist endpointów — strict match (in_array z true)
$allowed = [
    "/station/{$stationId}",
    "/station/{$stationId}/nowplaying",
    "/station/{$stationId}/reports/overview/charts",
    "/station/{$stationId}/reports/overview/best-and-worst",
    "/station/{$stationId}/history",
    "/station/{$stationId}/listeners",
    "/station/{$stationId}/reports/requests",
];

if (!in_array($path, $allowed, true)) {
    proxyLog('WARN', 'Niedozwolony endpoint', [
        'path'     => $path,
        'raw_path' => $rawPath,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '–',
    ]);
    jsonError('Niedozwolony endpoint.', 403);
}

proxyLog('DEBUG', 'Żądanie zaakceptowane', [
    'path'      => $path,
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? '–',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '–', 0, 80),
]);

// ─────────────────────────────────────────────────────────────
// 10. ŻĄDANIE DO UPSTREAM API (cURL)
// ─────────────────────────────────────────────────────────────

$upstreamUrl = $baseUrl . $path;

$ch = curl_init($upstreamUrl);
if ($ch === false) {
    jsonError('Wewnętrzny błąd serwera.', 500, 'curl_init() zwróciło false');
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,           // nie podążaj za przekierowaniami

    // Timeouty z konfiguracji
    CURLOPT_CONNECTTIMEOUT => max(1, $curlConnectTimeout),
    CURLOPT_TIMEOUT        => max(1, $curlTimeout),

    // Jawna weryfikacja TLS — nie polegaj na domyślnych wartościach
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,

    // Nagłówki — klucz API po sanitizacji
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'X-API-Key: ' . $apiKey,
        'User-Agent: AzuraCast-Proxy/2.0',
    ],

    // Nie wysyłaj ciasteczek z serwera proxy
    CURLOPT_COOKIE => '',
]);

$tsStart  = microtime(true);
$body     = curl_exec($ch);
$elapsed  = round((microtime(true) - $tsStart) * 1000); // ms

$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
$httpStatus  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// ─────────────────────────────────────────────────────────────
// 11. OBSŁUGA ODPOWIEDZI UPSTREAM
// ─────────────────────────────────────────────────────────────

// Błąd sieciowy cURL
if ($body === false || $curlErrno !== 0) {
    proxyLog('ERROR', 'Błąd cURL', [
        'errno'   => $curlErrno,
        'error'   => $curlError,   // szczegóły tylko w logu, nie dla klienta
        'path'    => $path,
        'time_ms' => $elapsed,
    ]);
    jsonError('Nie można połączyć się z serwerem radia.', 502);
}

proxyLog('DEBUG', 'Odpowiedź upstream', [
    'path'           => $path,
    'http_status'    => $httpStatus,
    'content_type'   => strtok($contentType, ';'),
    'body_bytes'     => strlen((string) $body),
    'time_ms'        => $elapsed,
]);

// Błąd HTTP po stronie upstream
if ($httpStatus < 200 || $httpStatus >= 300) {
    $safeStatus = ($httpStatus >= 400 && $httpStatus <= 599) ? $httpStatus : 502;

    proxyLog('ERROR', 'Upstream zwrócił błąd HTTP', [
        'upstream_status' => $httpStatus,
        'path'            => $path,
        'time_ms'         => $elapsed,
        // UWAGA: nie logujemy $body — może zawierać klucze/tokeny w komunikacie błędu AzuraCast
    ]);

    jsonError("Serwer radia zwrócił błąd (HTTP {$httpStatus}).", $safeStatus);
}

// Log sukcesu w trybie standard
proxyLog('INFO', 'OK', [
    'path'    => $path,
    'status'  => $httpStatus,
    'time_ms' => $elapsed,
]);

// ─────────────────────────────────────────────────────────────
// 12. WYSŁANIE ODPOWIEDZI
// ─────────────────────────────────────────────────────────────

// NIE przekazujemy X-Upstream-Content-Type — ujawniałoby informacje o upstream
// Content-Type: application/json; charset=utf-8 ustawiony już w sendSecurityHeaders()

echo $body;
