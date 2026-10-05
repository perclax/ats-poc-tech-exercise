<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$files = [
    '/' => [__DIR__.'/index.html', 'text/html'],
    '/refresh.test.js' => [__DIR__.'/refresh.test.js', 'text/javascript'],
    '/scripts/application.js' => [dirname(__DIR__, 2).'/public/scripts/application.js', 'text/javascript'],
    '/scripts/refresh.js' => [dirname(__DIR__, 2).'/public/scripts/refresh.js', 'text/javascript'],
    '/styles/application.css' => [dirname(__DIR__, 2).'/public/styles/application.css', 'text/css'],
];
header('Cache-Control: no-store');
if (!is_string($path) || !isset($files[$path])) {
    http_response_code(404);
    exit;
}
[$file, $type] = $files[$path];
header('Content-Type: '.$type.'; charset=UTF-8');
readfile($file);
