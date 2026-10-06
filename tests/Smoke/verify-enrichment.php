<?php

declare(strict_types=1);

use App\Applications\Domain\Application\ApplicationId;
use MongoDB\Client;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (!isset($argv[1], $argv[2])) {
    fwrite(\STDERR, "Usage: verify-enrichment.php <wait|cleanup> <application-id> [score] [summary]\n");
    exit(2);
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$mongoUri = $_SERVER['MONGODB_URI'] ?? null;
$databaseName = $_SERVER['MONGODB_DB'] ?? null;
if (!is_string($mongoUri) || !is_string($databaseName)) {
    throw new RuntimeException('The MongoDB smoke-test environment is unavailable.');
}

$mode = $argv[1];
$applicationId = ApplicationId::fromString($argv[2])->value;
$collection = (new Client($mongoUri))->selectCollection($databaseName, 'applications');

if ('cleanup' === $mode) {
    if (!isset($argv[3]) || 1 !== preg_match('/\A[0-9a-f]{16}\z/', $argv[3])) {
        throw new RuntimeException('Cleanup requires the fictional smoke correlation.');
    }
    $collection->deleteOne(['_id' => $applicationId, 'candidateEmail' => 'smoke-'.$argv[3].'@example.test']);
    if (null !== $collection->findOne(['_id' => $applicationId])) {
        throw new RuntimeException('The exact smoke application could not be safely removed.');
    }
    exit(0);
}

if ('wait' !== $mode || !isset($argv[3], $argv[4])) {
    fwrite(\STDERR, "The wait mode requires an expected score and summary.\n");
    exit(2);
}

$expectedScore = (int) $argv[3];
$expectedSummary = $argv[4];
$deadline = hrtime(true) + 15_000_000_000;

do {
    $document = $collection->findOne(['_id' => $applicationId], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
    $data = null === $document ? null : (array) $document;
    if (null !== $data && 'completed' === ($data['enrichmentStatus'] ?? null)) {
        if ($expectedScore !== ($data['enrichmentScore'] ?? null) || $expectedSummary !== ($data['enrichmentSummary'] ?? null)) {
            fwrite(\STDERR, "The completed enrichment did not contain the expected deterministic result.\n");
            exit(1);
        }
        if (!isset($data['enrichedAt'])) {
            fwrite(\STDERR, "The completed enrichment is missing enrichedAt.\n");
            exit(1);
        }

        fwrite(\STDOUT, sprintf("Application %s completed with the expected deterministic enrichment.\n", $applicationId));
        exit(0);
    }

    usleep(100_000);
} while (hrtime(true) < $deadline);

fwrite(\STDERR, "Application {$applicationId} did not complete within 15 seconds.\n");
exit(1);
