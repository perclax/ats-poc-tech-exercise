<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Console;

use App\Applications\Infrastructure\Demo\DemoApplicationSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:applications:seed-demo', description: 'Create four deterministic fictional demo applications without dispatching enrichment.')]
final class SeedDemoApplicationsCommand extends Command
{
    public function __construct(private readonly DemoApplicationSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('if-empty', null, InputOption::VALUE_NONE, 'Seed only when the applications collection is completely empty.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->getOption('if-empty')) {
                $result = $this->seeder->seedIfEmpty();
                if ($result->skipped) {
                    $output->writeln('Demo seed skipped: applications already exist.');

                    return self::SUCCESS;
                }
                $report = ['created' => $result->created, 'alreadyPresent' => $result->alreadyPresent];
            } else {
                $report = $this->seeder->seed();
            }
        } catch (\UnexpectedValueException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $output->writeln(\sprintf('Demo seed complete. Created: %d; already present: %d.', $report['created'], $report['alreadyPresent']));

        return self::SUCCESS;
    }
}
