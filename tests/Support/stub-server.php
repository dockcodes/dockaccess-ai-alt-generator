<?php

// Router for `php -S`: a stand-in for the alt generator, with misbehaving routes. Every request is
// appended to the JSON-lines file named by STUB_LOG, uploaded files included (base64).

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log  = (string) getenv('STUB_LOG');
$files = [];

foreach ($_FILES as $field => $file) {
    $files[$field] = [
        'name'  => $file['name'],
        'type'  => $file['type'],
        'bytes' => base64_encode((string) file_get_contents($file['tmp_name'])),
    ];
}

file_put_contents($log, json_encode([
    'method'  => $_SERVER['REQUEST_METHOD'],
    'path'    => $path,
    'query'   => $_GET,
    'files'   => $files,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
]) . "\n", FILE_APPEND);

$json = static function (int $status, array $body, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($body);
};

$route = explode('/', trim($path, '/'))[0];

switch ($route) {
    case 'ok':
        if (substr($path, -9) === '/api/user') {
            $json(200, ['requests' => ['limit' => 50, 'used' => 3]]);
            break;
        }

        $json(200, ['data' => [
            'set_uuid' => '11111111-2222-4333-8444-555555555555',
            'set_ref'  => $_GET['set_ref'] ?? null,
            'image'    => ['uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'filename' => 'x', 'alts' => [['alt' => 'A test alt in ' . ($_GET['lang'] ?? '?'), 'locale' => $_GET['lang'] ?? 'en']]],
        ]]);
        break;
    case 'redirect':
        header('Location: http://' . $_SERVER['HTTP_HOST'] . '/ok/api/generate', true, 302);
        break;
    case 'slow':
        sleep(3);
        $json(200, []);
        break;
    case 'huge':
        header('Content-Type: application/json');
        echo str_repeat(' ', 2 * 1024 * 1024);
        break;
    case 'headers':
        for ($i = 0; $i < 1500; $i++) {
            header('X-Pad-' . $i . ': ' . str_repeat('x', 60));
        }
        $json(200, []);
        break;
    case 'limit':
        $json(429, ['detail' => "You've reached your limit."], ['X-Alt-Reason' => 'limit_reached', 'X-Alt-Daily-Limit' => '50']);
        break;
    default:
        $json(404, ['detail' => 'Not Found']);
}
