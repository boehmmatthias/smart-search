<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Configuration;

use BoehmMatthias\SmartSearch\Configuration\SmartSearchConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SmartSearchConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function makeConfiguration(array $config): SmartSearchConfiguration
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('smart_search')->willReturn($config);

        return new SmartSearchConfiguration($extensionConfiguration);
    }

    #[Test]
    public function fallbackDefaultsApplyWhenNothingIsConfigured(): void
    {
        $configuration = $this->makeConfiguration([]);

        self::assertSame('http://localhost:8080', $configuration->getEmbeddingServerUrl());
        self::assertSame('http://localhost:8081', $configuration->getGenerationServerUrl());
        self::assertSame(512, $configuration->getGenerationMaxTokens());
        self::assertSame(300, $configuration->getGenerationTimeout());
        self::assertSame(6000, $configuration->getEmbeddingContextLength());
        self::assertSame(5, $configuration->getRagTopK());
        self::assertSame(800, $configuration->getDocumentContextLength());
        self::assertSame(0.30, $configuration->getSemanticThreshold());
        self::assertNull($configuration->getSystemPrompt());
    }

    #[Test]
    public function configuredValuesAreCastToTheDeclaredType(): void
    {
        // The Install Tool stores every value as a string.
        $configuration = $this->makeConfiguration([
            'generationMaxTokens' => '1024',
            'generationTimeout' => '60',
            'embeddingContextLength' => '4000',
            'ragTopK' => '10',
            'documentContextLength' => '1200',
            'semanticThreshold' => '0.75',
        ]);

        self::assertSame(1024, $configuration->getGenerationMaxTokens());
        self::assertSame(60, $configuration->getGenerationTimeout());
        self::assertSame(4000, $configuration->getEmbeddingContextLength());
        self::assertSame(10, $configuration->getRagTopK());
        self::assertSame(1200, $configuration->getDocumentContextLength());
        self::assertSame(0.75, $configuration->getSemanticThreshold());
    }

    #[Test]
    public function serverUrlsAreTrimmed(): void
    {
        $configuration = $this->makeConfiguration([
            'embeddingServerUrl' => '  http://embed.example:8080  ',
            'generationServerUrl' => "\thttp://gen.example:8081\n",
        ]);

        self::assertSame('http://embed.example:8080', $configuration->getEmbeddingServerUrl());
        self::assertSame('http://gen.example:8081', $configuration->getGenerationServerUrl());
    }

    #[Test]
    public function embeddingContextLengthFallsBackWhenClearedOrNonPositive(): void
    {
        // The Install Tool stores settings as strings, so clearing the field leaves '' — and
        // (int) '' is 0, which made normalise() truncate every document to the empty string.
        self::assertSame(6000, $this->makeConfiguration(['embeddingContextLength' => ''])->getEmbeddingContextLength());
        self::assertSame(6000, $this->makeConfiguration(['embeddingContextLength' => '0'])->getEmbeddingContextLength());
        self::assertSame(6000, $this->makeConfiguration(['embeddingContextLength' => 0])->getEmbeddingContextLength());

        // Negative was worse than zero: mb_substr($text, 0, -50) strips the tail.
        self::assertSame(6000, $this->makeConfiguration(['embeddingContextLength' => '-50'])->getEmbeddingContextLength());

        self::assertSame(1, $this->makeConfiguration(['embeddingContextLength' => '1'])->getEmbeddingContextLength());
    }

    #[Test]
    public function systemPromptIsNullWhenBlankSoTheBuiltInDefaultWins(): void
    {
        self::assertNull($this->makeConfiguration(['systemPrompt' => ''])->getSystemPrompt());
        self::assertNull($this->makeConfiguration(['systemPrompt' => '   '])->getSystemPrompt());
        self::assertSame(
            'Answer tersely.',
            $this->makeConfiguration(['systemPrompt' => '  Answer tersely.  '])->getSystemPrompt(),
        );
    }
}
