<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Generation;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Generation\ConfigurableGenerationClient;
use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;
use BoehmMatthias\SmartSearch\Generation\LlamaCppGenerationClient;
use BoehmMatthias\SmartSearch\Generation\OllamaGenerationClient;
use BoehmMatthias\SmartSearch\Generation\OpenAiGenerationClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigurableGenerationClientTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function providerToClass(): array
    {
        return [
            'llamacpp' => ['llamacpp', LlamaCppGenerationClient::class],
            'ollama' => ['ollama', OllamaGenerationClient::class],
            'openai' => ['openai', OpenAiGenerationClient::class],
            'unknown falls back' => ['nonsense', LlamaCppGenerationClient::class],
        ];
    }

    #[Test]
    #[DataProvider('providerToClass')]
    public function dispatchesToTheConfiguredProvider(string $provider, string $expectedClass): void
    {
        $configuration = $this->createMock(SmartSearchConfiguration::class);
        $configuration->method('getGenerationProvider')->willReturn($provider);

        $clients = [
            LlamaCppGenerationClient::class => $this->createMock(GenerationClientInterface::class),
            OllamaGenerationClient::class => $this->createMock(GenerationClientInterface::class),
            OpenAiGenerationClient::class => $this->createMock(GenerationClientInterface::class),
        ];

        foreach ($clients as $class => $mock) {
            $mock->expects($class === $expectedClass ? self::once() : self::never())
                ->method('complete')
                ->willReturn('answer');
        }

        $client = new ConfigurableGenerationClient(
            $configuration,
            $clients[LlamaCppGenerationClient::class],
            $clients[OllamaGenerationClient::class],
            $clients[OpenAiGenerationClient::class],
        );

        self::assertSame('answer', $client->complete([['role' => 'user', 'content' => 'q']]));
    }
}
