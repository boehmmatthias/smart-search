<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;
use BoehmMatthias\SmartSearch\Service\GenerationService;
use BoehmMatthias\SmartSearch\ValueObject\ConversationHistory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GenerationServiceTest extends TestCase
{
    private GenerationClientInterface&MockObject $client;
    private SmartSearchConfiguration&MockObject $configuration;
    private GenerationService $service;

    protected function setUp(): void
    {
        $this->client = $this->createMock(GenerationClientInterface::class);
        $this->configuration = $this->createMock(SmartSearchConfiguration::class);
        $this->configuration->method('getSystemPrompt')->willReturn(null);
        $this->service = new GenerationService($this->client, $this->configuration);
    }

    #[Test]
    public function generateJoinsContextBlocksInUserMessage(): void
    {
        $this->client
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(function (array $messages): bool {
                $userContent = $messages[1]['content'] ?? '';
                return str_contains($userContent, 'Block one')
                    && str_contains($userContent, 'Block two');
            }))
            ->willReturn('Answer.');

        $this->service->generate('my question', ['Block one', 'Block two']);
    }

    #[Test]
    public function generateIncludesQueryInUserMessage(): void
    {
        $this->client
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(function (array $messages): bool {
                $userContent = $messages[1]['content'] ?? '';
                return str_contains($userContent, 'What is TYPO3?');
            }))
            ->willReturn('Answer.');

        $this->service->generate('What is TYPO3?', ['Some context.']);
    }

    #[Test]
    public function generateIncludesSystemMessage(): void
    {
        $this->client
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(function (array $messages): bool {
                return ($messages[0]['role'] ?? '') === 'system'
                    && !empty($messages[0]['content']);
            }))
            ->willReturn('Answer.');

        $this->service->generate('question', ['context']);
    }

    #[Test]
    public function generateReturnsClientResponse(): void
    {
        $this->client->method('complete')->willReturn('The generated answer.');

        $result = $this->service->generate('query', ['context']);

        self::assertSame('The generated answer.', $result);
    }

    #[Test]
    public function generateUsesInlineSystemPromptOverride(): void
    {
        $this->client
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(function (array $messages): bool {
                return ($messages[0]['content'] ?? '') === 'Custom inline prompt.';
            }))
            ->willReturn('Answer.');

        $this->service->generate('question', ['context'], 'Custom inline prompt.');
    }

    #[Test]
    public function generateUsesConfiguredSystemPromptWhenNoOverrideGiven(): void
    {
        $configuration = $this->createMock(SmartSearchConfiguration::class);
        $configuration->method('getSystemPrompt')->willReturn('Config-level prompt.');
        $service = new GenerationService($this->client, $configuration);

        $this->client
            ->expects(self::once())
            ->method('complete')
            ->with(self::callback(function (array $messages): bool {
                return ($messages[0]['content'] ?? '') === 'Config-level prompt.';
            }))
            ->willReturn('Answer.');

        $service->generate('question', ['context']);
    }

    #[Test]
    public function conversationHistoryIsInsertedBetweenTheSystemMessageAndTheQuestion(): void
    {
        $history = ConversationHistory::empty()
            ->withUserMessage('What is caching?')
            ->withAssistantMessage('A way to reuse computed results.');

        $captured = [];
        $this->client
            ->method('complete')
            ->willReturnCallback(static function (array $messages) use (&$captured): string {
                $captured = $messages;
                return 'answer';
            });

        $this->service->generate('And how do I configure it?', ['doc'], history: $history);

        self::assertSame(
            ['system', 'user', 'assistant', 'user'],
            array_column($captured, 'role'),
        );
        self::assertSame('What is caching?', $captured[1]['content']);
        self::assertStringContainsString('And how do I configure it?', $captured[3]['content']);
    }

    #[Test]
    public function historyAndSystemPromptAreIndependent(): void
    {
        // The original branch put history in the same positional slot as $systemPrompt, so
        // using one meant losing the other. Both must work together.
        $history = ConversationHistory::empty()->withUserMessage('earlier');

        $captured = [];
        $this->client
            ->method('complete')
            ->willReturnCallback(static function (array $messages) use (&$captured): string {
                $captured = $messages;
                return 'answer';
            });

        $this->service->generate('q', ['doc'], systemPrompt: 'Be terse.', history: $history);

        self::assertSame('Be terse.', $captured[0]['content']);
        self::assertSame('earlier', $captured[1]['content']);
    }

    #[Test]
    public function anEmptyHistoryAddsNoMessages(): void
    {
        $captured = [];
        $this->client
            ->method('complete')
            ->willReturnCallback(static function (array $messages) use (&$captured): string {
                $captured = $messages;
                return 'answer';
            });

        $this->service->generate('q', ['doc'], history: ConversationHistory::empty());

        self::assertSame(['system', 'user'], array_column($captured, 'role'));
    }
}
