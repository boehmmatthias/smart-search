<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Command;

use BoehmMatthias\SmartSearch\Command\ClearCollectionCommand;
use BoehmMatthias\SmartSearch\Repository\VectorRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ClearCollectionCommandTest extends TestCase
{
    private VectorRepository&MockObject $vectorRepository;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->vectorRepository = $this->createMock(VectorRepository::class);
        $this->tester = new CommandTester(new ClearCollectionCommand($this->vectorRepository));
    }

    #[Test]
    public function deletesTheCollectionWhenConfirmed(): void
    {
        $this->vectorRepository
            ->expects(self::once())
            ->method('deleteByCollection')
            ->with('docs');

        $this->tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['collection' => 'docs']));
    }

    #[Test]
    public function deletesNothingWhenTheConfirmationIsDeclined(): void
    {
        // Deletion is not recoverable from inside this extension — it does not know the source
        // records — so declining must be a genuine no-op, not a slower yes.
        $this->vectorRepository->expects(self::never())->method('deleteByCollection');

        $this->tester->setInputs(['no']);
        $this->tester->execute(['collection' => 'docs']);

        self::assertStringContainsString('Aborted.', $this->tester->getDisplay());
    }

    #[Test]
    public function defaultsToNotDeletingWhenTheAnswerIsEmpty(): void
    {
        $this->vectorRepository->expects(self::never())->method('deleteByCollection');

        $this->tester->setInputs(['']);
        $this->tester->execute(['collection' => 'docs']);

        self::assertStringContainsString('Aborted.', $this->tester->getDisplay());
    }

    #[Test]
    public function skipsTheConfirmationWithTheYesOption(): void
    {
        $this->vectorRepository
            ->expects(self::once())
            ->method('deleteByCollection')
            ->with('docs');

        self::assertSame(
            Command::SUCCESS,
            $this->tester->execute(['collection' => 'docs', '--yes' => true]),
        );
    }
}
