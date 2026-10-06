<?php
declare(strict_types=1);

/**
 * Server-side Cloudflare Turnstile verification endpoint.
 *
 * The Turnstile secret remains server-side. The browser only submits the
 * one-time verification token returned by the Turnstile widget.
 */

require_once __DIR__ . '/includes/bootstrap.php';

set_time_limit(15);
error_reporting(0);

sendSecurityHeaders();

try {
    loadEnvFile();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(
        ['success' => false, 'error' => 'Server configuration error.', 'status' => 500],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

startSecureSession();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(
        ['success' => false, 'error' => 'Only POST requests are allowed.', 'status' => 405],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

$input = readJsonBody();
$token = is_string($input['token'] ?? null) ? trim($input['token']) : '';
$secretKey = trim(cfg('TURNSTILE_SECRET_KEY', ''));
$expectedHostname = trim(cfg('TURNSTILE_EXPECTED_HOSTNAME', ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(
        ['success' => false, 'error' => 'Missing Turnstile token.', 'status' => 400],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($secretKey === '') {
    http_response_code(500);
    echo json_encode(
        ['success' => false, 'error' => 'Server configuration error.', 'status' => 500],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(
        ['success' => false, 'error' => 'The PHP cURL extension is required.', 'status' => 500],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

$curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
if ($curl === false) {
    http_response_code(500);
    echo json_encode(
        ['success' => false, 'error' => 'Internal server error.', 'status' => 500],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'secret' => $secretKey,
        'response' => $token,
        'remoteip' => $ip,
    ]),
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => max(1, (int) cfg('TURNSTILE_VERIFY_TIMEOUT', '10')),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($curl);
$curlErrno = curl_errno($curl);
$curlError = curl_error($curl);
$httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);

if ($response === false || $curlErrno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
    error_log('Turnstile verification request failed: ' . $curlError);

    http_response_code(502);
    echo json_encode(
        ['success' => false, 'error' => 'Security verification service is unavailable.', 'status' => 502],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

$result = json_decode((string) $response, true);

if (!is_array($result) || empty($result['success'])) {
    http_response_code(403);
    echo json_encode(
        ['success' => false, 'error' => 'Security verification failed.', 'status' => 403],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (
    $expectedHostname !== '' &&
    (!isset($result['hostname']) || !hash_equals($expectedHostname, (string) $result['hostname']))
) {
    http_response_code(403);
    echo json_encode(
        ['success' => false, 'error' => 'Security verification failed.', 'status' => 403],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

session_regenerate_id(true);
$_SESSION['cf_verified'] = true;
$_SESSION['cf_verified_at'] = time();

echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
