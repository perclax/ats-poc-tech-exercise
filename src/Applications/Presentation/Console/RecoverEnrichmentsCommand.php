<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Console;

use App\Applications\Application\Command\RecoverEnrichments;
use App\Applications\Application\Command\RecoverEnrichmentsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:applications:recover-enrichments',
    description: 'Redispatch pending and stale processing application enrichments.',
)]
final class RecoverEnrichmentsCommand extends Command
{
    public function __construct(private readonly RecoverEnrichmentsHandler $handler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stale-after', null, InputOption::VALUE_REQUIRED, 'Seconds before processing enrichment is stale.', '900')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum applications to inspect and redispatch.', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report eligible applications without changing state or dispatching messages.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $staleAfter = $this->positiveIntegerOption($input, 'stale-after');
        $limit = $this->positiveIntegerOption($input, 'limit');
        if (null === $staleAfter || null === $limit) {
            $output->writeln('<error>--stale-after and --limit must be positive integers.</error>');

            return self::INVALID;
        }

        $report = ($this->handler)(new RecoverEnrichments($staleAfter, $limit, (bool) $input->getOption('dry-run')));
        $output->writeln(\sprintf(
            '%s Eligible: %d; reset: %d; dispatched: %d; skipped: %d; dispatch failures: %d.',
            $report->dryRun ? 'Dry run.' : 'Recovery complete.',
            $report->eligible,
            $report->reset,
            $report->dispatched,
            $report->skipped,
            $report->dispatchFailures,
        ));

        return $report->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    private function positiveIntegerOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || 1 !== preg_match('/\A[1-9][0-9]*\z/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
