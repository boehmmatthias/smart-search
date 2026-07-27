<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

/**
 * Streaming counterpart to GenerationClientInterface.
 *
 * Implementations call $onChunk once per text delta as it arrives, and MUST throw on transport
 * failure or an unexpected payload rather than returning silently — a caller that received no
 * chunks cannot otherwise tell an empty answer from a failed request.
 */
interface StreamingGenerationClientInterface
{
    /**
     * @param array<array{role: string, content: string}> $messages
     * @param callable(string): void $onChunk Called with each text delta as it arrives.
     * @throws \RuntimeException on transport failure or an unexpected payload
     */
    public function stream(array $messages, callable $onChunk): void;
}
