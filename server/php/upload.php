<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

$config = include __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['fileSize']) || !isset($body['mimeType'])) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Missing fileSize or mimeType']);
    exit;
}

if ($body['fileSize'] > $config['max_file_size']) {
    ob_end_clean();
    http_response_code(413);
    echo json_encode(['error' => 'File too large', 'maxSize' => $config['max_file_size']]);
    exit;
}

$uploadId = bin2hex(random_bytes(16));

$tokenRequestBody = [
    'uploadId'   => $uploadId,
    'userId'     => $_SESSION['user_id'],
    'fileSize'   => $body['fileSize'],
    'mimeType'   => $body['mimeType'],
    'metadata'   => $body['metadata'] ?? [],
];

$ch = curl_init($config['microservice_url'] . '/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($tokenRequestBody),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['service_token'],
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    ob_end_clean();
    http_response_code(502);
    echo json_encode(['error' => 'Failed to obtain upload token', 'detail' => $curlError ?: 'upstream returned ' . $httpCode]);
    exit;
}

$tokenData = json_decode($response, true);

ob_end_clean();
echo json_encode([
    'token'     => $tokenData['token'],
    'uploadUrl' => $config['microservice_url'] . '/upload',
    'uploadId'  => $uploadId,
]);
