<?php

declare(strict_types=1);

namespace App\Infrastructure\Probe\RabbitMq;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:probe:rabbitmq', description: 'Verify a message round trip through RabbitMQ and the worker.')]
final class RabbitMqProbeCommand extends Command
{
    private const int TIMEOUT_MICROSECONDS = 10_000_000;
    private const int POLL_INTERVAL_MICROSECONDS = 100_000;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ProbeReceiptStore $receiptStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removedStaleReceipts = $this->receiptStore->removeStale();
        $correlationId = bin2hex(random_bytes(32));
        $this->receiptStore->remove($correlationId);
        $this->messageBus->dispatch(new InfrastructureProbeMessage($correlationId));

        $deadline = hrtime(true) + (self::TIMEOUT_MICROSECONDS * 1_000);
        while (hrtime(true) < $deadline) {
            if ($this->receiptStore->has($correlationId)) {
                $this->receiptStore->remove($correlationId);
                $output->writeln(\sprintf(
                    '<info>RabbitMQ round trip verified for probe %s; removed %d stale receipt(s).</info>',
                    $correlationId,
                    $removedStaleReceipts,
                ));

                return self::SUCCESS;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $output->writeln(\sprintf('<error>RabbitMQ probe %s was not consumed within 10 seconds.</error>', $correlationId));

        return self::FAILURE;
    }
}
