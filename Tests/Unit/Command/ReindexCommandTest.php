<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Command;

use BoehmMatthias\SmartSearch\Command\ReindexCommand;
use BoehmMatthias\SmartSearch\Command\ReindexCommandInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReindexCommandTest extends TestCase
{
    /**
     * @param array<string, int|\Throwable> $handlers Collection name => record count, or a throwable.
     */
    private function makeTester(array $handlers): CommandTester
    {
        $services = [];
        foreach ($handlers as $collection => $outcome) {
            $handler = $this->createMock(ReindexCommandInterface::class);
            $handler->method('getCollection')->willReturn((string) $collection);
            $handler->method('getLabel')->willReturn((string) $collection . ' label');

            if ($outcome instanceof \Throwable) {
                $handler->method('reindex')->willThrowException($outcome);
            } else {
                $handler->method('reindex')->willReturn($outcome);
            }

            $services[] = $handler;
        }

        return new CommandTester(new ReindexCommand($services));
    }

    #[Test]
    public function warnsInsteadOfFailingWhenNoHandlersAreRegistered(): void
    {
        $tester = $this->makeTester([]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No reindex handlers registered', $tester->getDisplay());
    }

    #[Test]
    public function runsEveryHandlerWhenNoCollectionIsGiven(): void
    {
        $tester = $this->makeTester(['articles' => 12, 'faq' => 3]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Indexed 12 record(s).', $display);
        self::assertStringContainsString('Indexed 3 record(s).', $display);
    }

    #[Test]
    public function runsOnlyTheRequestedCollection(): void
    {
        $tester = $this->makeTester(['articles' => 12, 'faq' => 3]);

        $tester->execute(['collection' => 'faq']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Indexed 3 record(s).', $display);
        self::assertStringNotContainsString('Indexed 12 record(s).', $display);
    }

    #[Test]
    public function warnsWhenTheRequestedCollectionMatchesNoHandler(): void
    {
        // Otherwise a typo'd collection name is a silent no-op reported as success.
        $tester = $this->makeTester(['articles' => 12]);

        $tester->execute(['collection' => 'atricles']);

        self::assertStringContainsString('No handler is registered for collection "atricles"', $tester->getDisplay());
    }

    #[Test]
    public function reportsFailureWhenAHandlerThrows(): void
    {
        $tester = $this->makeTester(['articles' => new \RuntimeException('embedding server down')]);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('embedding server down', $tester->getDisplay());
    }
}
