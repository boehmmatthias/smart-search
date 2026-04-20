<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Generation;

interface GenerationClientInterface
{
    /**
     * @param array<array{role: string, content: string}> $messages
     */
    public function complete(array $messages): string;
}
