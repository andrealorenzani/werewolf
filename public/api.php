<?php
declare(strict_types=1);

// Single HTTP entry point of the JSON API: api.php?a=<action>. See src/Api.php.
require __DIR__ . '/../src/bootstrap.php';

use Wm\Api;
use Wm\Store;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/../config/config.php';
$action = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';

$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, 150_000);
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request body.']);
        exit;
    }
} elseif (!in_array($action, ['defs', 'info', 'rules'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST.']);
    exit;
}
if ($action === 'info') {
    $body['id'] = $_GET['id'] ?? '';
}

[$status, $payload] = (new Api(new Store($config['data_dir'])))->handle($action, $body);
http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
