<?php

declare(strict_types=1);

$publicDirectory = __DIR__;
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$requestedFile = false;

if (\is_string($requestPath)) {
    $requestedFile = realpath($publicDirectory.rawurldecode($requestPath));
}

if (false !== $requestedFile
    && str_starts_with($requestedFile, $publicDirectory.\DIRECTORY_SEPARATOR)
    && is_file($requestedFile)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicDirectory.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require $_SERVER['SCRIPT_FILENAME'];
