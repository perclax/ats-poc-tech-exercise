<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Console;

use App\Applications\Infrastructure\Demo\DemoApplicationSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:applications:seed-demo', description: 'Create fictional demo applications when the database is empty.')]
final class SeedDemoApplicationsCommand extends Command
{
    public function __construct(private readonly DemoApplicationSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = $this->seeder->seedIfEmpty();
        $output->writeln(0 === $created
            ? 'Demo seed skipped: applications already exist.'
            : \sprintf('Created %d demo applications.', $created));

        return self::SUCCESS;
    }
}
