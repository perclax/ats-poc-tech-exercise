<?php

declare(strict_types=1);

namespace App\Tests\Functional\Applications;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Job\JobId;
use App\Applications\Presentation\Console\RecoverEnrichmentsCommand;
use App\Tests\Support\MongoDbTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecoverEnrichmentsCommandTest extends MongoDbTestCase
{
    public function testDryRunAndRepeatedPendingRecoveryAreStateSafe(): void
    {
        $this->savePendingApplication();
        $transport = self::getContainer()->get('messenger.transport.enrichment_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $tester = $this->commandTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Dry run. Eligible: 1', $tester->getDisplay());
        self::assertCount(0, $transport->getSent());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('dispatched: 1', $tester->getDisplay());
        self::assertCount(1, $transport->getSent());

        $transport->reset();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertCount(1, $transport->getSent());
    }

    public function testInvalidOptionsReturnInvalidExitCode(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('must be positive integers', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        $command = self::getContainer()->get(RecoverEnrichmentsCommand::class);
        self::assertInstanceOf(RecoverEnrichmentsCommand::class, $command);

        return new CommandTester($command);
    }

    private function savePendingApplication(): void
    {
        $repository = self::getContainer()->get(ApplicationRepository::class);
        self::assertInstanceOf(ApplicationRepository::class, $repository);
        $repository->save(new Application(
            ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc'),
            new Candidate('Recovery Candidate', new EmailAddress('recovery@example.test'), null),
            new JobId('backend-developer'),
            null,
            'PHP',
            new \DateTimeImmutable('2026-10-03T10:00:00+00:00'),
        ));
    }
}
