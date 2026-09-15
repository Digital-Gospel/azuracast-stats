<?php
declare(strict_types=1);

const SECRET_FILE = './azuracast-api-proxy.env';

session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Dozwolona tylko metoda POST']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';

// Pobranie SECRET_KEY ze środowiska lub z pliku .env (analogicznie jak w proxy)
$secretKey = getenv('TURNSTILE_SECRET_KEY') ?: '';

if (!$secretKey && file_exists(SECRET_FILE)) {
    $lines = file(SECRET_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), 'TURNSTILE_SECRET_KEY=') === 0) {
            $secretKey = trim(substr(trim($line), strlen('TURNSTILE_SECRET_KEY=')));
            break;
        }
    }
}

if (empty($token) || empty($secretKey)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak tokena lub konfiguracji klucza']);
    exit;
}

// Weryfikacja tokena w API Cloudflare
$ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => $ip,
    ]),
    CURLOPT_TIMEOUT        => 10,
]);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode((string)$response, true);

if (!empty($result['success'])) {
    $_SESSION['cf_verified'] = true;
    $_SESSION['cf_verified_at'] = time();
    echo json_encode(['success' => true]);
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Weryfikacja nie powiodła się', 'codes' => $result['error-codes'] ?? []]);
}