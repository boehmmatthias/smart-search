<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;
use BoehmMatthias\SmartSearch\Service\GenerationService;

final class GenerationServiceTest extends TestCase
{
    private GenerationClientInterface&MockObject $client;
    private GenerationService $service;

    protected function setUp(): void
    {
        $this->client = $this->createMock(GenerationClientInterface::class);
        $this->service = new GenerationService($this->client);
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
}
