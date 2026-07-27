<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Command;

use BoehmMatthias\SmartSearch\Command\StatsCommand;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StatsCommandTest extends TestCase
{
    private VectorRepository&MockObject $vectorRepository;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->vectorRepository = $this->createMock(VectorRepository::class);
        $this->tester = new CommandTester(new StatsCommand($this->vectorRepository));
    }

    #[Test]
    public function reportsWhenNothingIsIndexed(): void
    {
        $this->vectorRepository->method('getCollectionStats')->willReturn([]);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('No collections indexed yet.', $this->tester->getDisplay());
    }

    #[Test]
    public function rendersCountsAndTimestamps(): void
    {
        $this->vectorRepository->method('getCollectionStats')->willReturn([
            ['collection' => 'docs', 'count' => 1234, 'last_indexed' => 1700000000],
        ]);

        $this->tester->execute([]);
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('docs', $display);
        self::assertStringContainsString('1,234', $display);
        self::assertStringContainsString(date('Y-m-d H:i:s', 1700000000), $display);
    }

    #[Test]
    public function rendersADashRatherThanTheEpochForANeverIndexedCollection(): void
    {
        // date('Y-m-d H:i:s', 0) is 1970-01-01, which reads as a real timestamp.
        $this->vectorRepository->method('getCollectionStats')->willReturn([
            ['collection' => 'empty', 'count' => 0, 'last_indexed' => 0],
        ]);

        $this->tester->execute([]);

        self::assertStringNotContainsString('1970', $this->tester->getDisplay());
    }
}
