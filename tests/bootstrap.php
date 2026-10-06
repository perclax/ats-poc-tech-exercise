<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ('ats_test' !== ($_SERVER['MONGODB_DB'] ?? null)) {
    throw new RuntimeException('Tests require the isolated ats_test MongoDB database.');
}
