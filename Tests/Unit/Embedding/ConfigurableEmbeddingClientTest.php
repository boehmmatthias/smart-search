<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Embedding;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use BoehmMatthias\SmartSearch\Embedding\ConfigurableEmbeddingClient;
use BoehmMatthias\SmartSearch\Embedding\EmbeddingClientInterface;
use BoehmMatthias\SmartSearch\Embedding\LlamaCppEmbeddingClient;
use BoehmMatthias\SmartSearch\Embedding\OllamaEmbeddingClient;
use BoehmMatthias\SmartSearch\Embedding\OpenAiEmbeddingClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigurableEmbeddingClientTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function providerToClass(): array
    {
        return [
            'llamacpp' => ['llamacpp', LlamaCppEmbeddingClient::class],
            'ollama' => ['ollama', OllamaEmbeddingClient::class],
            'openai' => ['openai', OpenAiEmbeddingClient::class],
            // Anything unrecognised falls back rather than throwing: a typo that silently
            // switched models would poison a collection, since vectors from different models
            // are not comparable.
            'unknown falls back' => ['nonsense', LlamaCppEmbeddingClient::class],
        ];
    }

    #[Test]
    #[DataProvider('providerToClass')]
    public function dispatchesToTheConfiguredProvider(string $provider, string $expectedClass): void
    {
        $configuration = $this->createMock(SmartSearchConfiguration::class);
        $configuration->method('getEmbeddingProvider')->willReturn($provider);

        $clients = [
            LlamaCppEmbeddingClient::class => $this->createMock(EmbeddingClientInterface::class),
            OllamaEmbeddingClient::class => $this->createMock(EmbeddingClientInterface::class),
            OpenAiEmbeddingClient::class => $this->createMock(EmbeddingClientInterface::class),
        ];

        foreach ($clients as $class => $mock) {
            $mock->expects($class === $expectedClass ? self::once() : self::never())
                ->method('embed')
                ->willReturn([0.1]);
        }

        $client = new ConfigurableEmbeddingClient(
            $configuration,
            $clients[LlamaCppEmbeddingClient::class],
            $clients[OllamaEmbeddingClient::class],
            $clients[OpenAiEmbeddingClient::class],
        );

        self::assertSame([0.1], $client->embed('text'));
    }
}
