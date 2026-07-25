<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

interface GenerationClientInterface
{
    /**
     * Run a chat completion and return the assistant's message content.
     *
     * Implementations MUST throw on transport failure or an unexpected payload rather than
     * returning an empty string, which a caller cannot distinguish from a genuine empty answer.
     *
     * @param array<array{role: string, content: string}> $messages
     * @throws \RuntimeException on transport failure or an unexpected payload
     */
    public function complete(array $messages): string;
}
