<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\ValueObject;

use BoehmMatthias\SmartSearch\ValueObject\ConversationHistory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationHistoryTest extends TestCase
{
    #[Test]
    public function anEmptyHistoryHasNoMessages(): void
    {
        $history = ConversationHistory::empty();

        self::assertTrue($history->isEmpty());
        self::assertSame(0, $history->count());
        self::assertSame([], $history->toArray());
    }

    #[Test]
    public function appendingReturnsANewInstanceAndLeavesTheOriginalUntouched(): void
    {
        $original = ConversationHistory::empty();
        $with = $original->withUserMessage('Hello');

        self::assertTrue($original->isEmpty());
        self::assertSame(1, $with->count());
        self::assertNotSame($original, $with);
    }

    #[Test]
    public function messagesCarryTheirRoleAndContentInOrder(): void
    {
        $history = ConversationHistory::empty()
            ->withUserMessage('Hi')
            ->withAssistantMessage('Hello!');

        self::assertSame(
            [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello!'],
            ],
            $history->toArray(),
        );
    }

    #[Test]
    public function truncatedKeepsTheMostRecentTurns(): void
    {
        $history = ConversationHistory::empty()
            ->withUserMessage('Q1')->withAssistantMessage('A1')
            ->withUserMessage('Q2')->withAssistantMessage('A2')
            ->withUserMessage('Q3')->withAssistantMessage('A3');

        $truncated = $history->truncated(2);

        // Trimmed from the front: a context window running out needs the recent turns, not the
        // opening ones.
        self::assertSame(
            ['Q2', 'A2', 'Q3', 'A3'],
            array_column($truncated->toArray(), 'content'),
        );
    }

    #[Test]
    public function truncatedIsANoOpWhenTheHistoryAlreadyFits(): void
    {
        $history = ConversationHistory::empty()->withUserMessage('Q1')->withAssistantMessage('A1');

        self::assertSame($history->toArray(), $history->truncated(5)->toArray());
    }

    #[Test]
    public function truncatedToZeroOrFewerTurnsIsEmpty(): void
    {
        $history = ConversationHistory::empty()->withUserMessage('Hi');

        self::assertTrue($history->truncated(0)->isEmpty());
        self::assertTrue($history->truncated(-1)->isEmpty());
    }
}
