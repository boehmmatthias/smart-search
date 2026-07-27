<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Command;

use BoehmMatthias\SmartSearch\Command\CleanupCommand;
use BoehmMatthias\SmartSearch\Command\OrphanProviderInterface;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupCommandTest extends TestCase
{
    private VectorRepository&MockObject $vectorRepository;

    protected function setUp(): void
    {
        $this->vectorRepository = $this->createMock(VectorRepository::class);
    }

    /**
     * @param array<string, string[]> $providers Collection name => live identifiers.
     */
    private function makeTester(array $providers): CommandTester
    {
        $services = [];
        foreach ($providers as $collection => $live) {
            $provider = $this->createMock(OrphanProviderInterface::class);
            $provider->method('getCollection')->willReturn((string) $collection);
            $provider->method('getLiveIdentifiers')->willReturn($live);
            $services[] = $provider;
        }

        return new CommandTester(new CleanupCommand($this->vectorRepository, $services));
    }

    #[Test]
    public function warnsWhenNoProvidersAreRegistered(): void
    {
        $tester = $this->makeTester([]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No orphan providers registered', $tester->getDisplay());
    }

    #[Test]
    public function passesLiveIdentifiersToTheRepositoryAndReportsTheCount(): void
    {
        $this->vectorRepository
            ->expects(self::once())
            ->method('deleteOrphans')
            ->with('articles', ['1', '2'], false)
            ->willReturn(3);

        $tester = $this->makeTester(['articles' => ['1', '2']]);
        $tester->execute([]);

        self::assertStringContainsString('Deleted 3 orphaned vector(s).', $tester->getDisplay());
    }

    #[Test]
    public function refusesToProceedWhenAProviderReportsNothingLive(): void
    {
        // An empty list is indistinguishable here from a provider that failed to load, and the
        // wrong guess wipes the collection. The command must stop, not delete.
        $this->vectorRepository
            ->method('deleteOrphans')
            ->willThrowException(new \InvalidArgumentException('Refusing to treat every vector as an orphan.'));

        $tester = $this->makeTester(['articles' => []]);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('--allow-empty', $tester->getDisplay());
    }

    #[Test]
    public function forwardsAllowEmptyWhenTheOperatorOptsIn(): void
    {
        $this->vectorRepository
            ->expects(self::once())
            ->method('deleteOrphans')
            ->with('articles', [], true)
            ->willReturn(9);

        $tester = $this->makeTester(['articles' => []]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--allow-empty' => true]));
    }

    #[Test]
    public function runsOnlyTheRequestedCollection(): void
    {
        $this->vectorRepository
            ->expects(self::once())
            ->method('deleteOrphans')
            ->with('faq', self::anything(), self::anything())
            ->willReturn(0);

        $tester = $this->makeTester(['articles' => ['1'], 'faq' => ['9']]);

        $tester->execute(['collection' => 'faq']);
    }

    #[Test]
    public function warnsWhenTheRequestedCollectionMatchesNoProvider(): void
    {
        $this->vectorRepository->expects(self::never())->method('deleteOrphans');

        $tester = $this->makeTester(['articles' => ['1']]);
        $tester->execute(['collection' => 'nope']);

        self::assertStringContainsString('No provider is registered for collection "nope"', $tester->getDisplay());
    }
}
