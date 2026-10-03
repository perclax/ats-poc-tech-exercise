<?php

declare(strict_types=1);

namespace App\Infrastructure\Probe\MongoDb;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:probe:mongodb', description: 'Verify the application MongoDB connection.')]
final class MongoDbProbeCommand extends Command
{
    public function __construct(private readonly DocumentManager $documentManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $databaseName = $this->documentManager->getConfiguration()->getDefaultDB();
        if (null === $databaseName) {
            throw new \RuntimeException('The default MongoDB database is not configured.');
        }

        $result = $this->documentManager->getClient()
            ->selectDatabase($databaseName)
            ->command(['ping' => 1])
            ->toArray();

        if (($result[0]['ok'] ?? 0.0) !== 1.0) {
            $output->writeln('<error>MongoDB ping failed.</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>MongoDB connection verified.</info>');

        return self::SUCCESS;
    }
}
