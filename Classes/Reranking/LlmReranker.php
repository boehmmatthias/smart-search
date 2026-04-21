<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Reranking;

use Psr\Log\LoggerInterface;
use BoehmMatthias\SmartSearch\Generation\GenerationClientInterface;

/**
 * Re-ranks candidates by asking the LLM to score each one for relevance.
 *
 * The LLM is prompted to return a JSON array of identifiers ordered best-first.
 * Falls back to the original order if the response cannot be parsed.
 */
class LlmReranker implements RerankerInterface
{
    public function __construct(
        private readonly GenerationClientInterface $generationClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function rerank(string $query, array $candidates): array
    {
        if (count($candidates) <= 1) {
            return $candidates;
        }

        $identifierList = implode(', ', array_column($candidates, 'identifier'));

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a relevance ranking assistant. '
                    . 'Given a query and a list of document identifiers, '
                    . 'return ONLY a JSON array of the identifiers ordered from most to least relevant. '
                    . 'Include all identifiers. Do not add any explanation.',
            ],
            [
                'role' => 'user',
                'content' => sprintf(
                    "Query: %s\n\nDocument identifiers: [%s]\n\nReturn a JSON array ordered best-first.",
                    $query,
                    $identifierList
                ),
            ],
        ];

        try {
            $response = $this->generationClient->complete($messages);

            // Extract JSON array from response (model may wrap it in prose)
            if (preg_match('/\[([^\]]+)\]/', $response, $matches)) {
                $ranked = json_decode('[' . $matches[1] . ']', true, 512, JSON_THROW_ON_ERROR);
                if (is_array($ranked)) {
                    return $this->reorderByRankedIdentifiers($candidates, array_map('strval', $ranked));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('LlmReranker failed, falling back to original order', [
                'error' => $e->getMessage(),
            ]);
        }

        return $candidates;
    }

    /**
     * @param array<array{identifier: string, score: float}> $candidates
     * @param string[] $rankedIdentifiers
     * @return array<array{identifier: string, score: float}>
     */
    private function reorderByRankedIdentifiers(array $candidates, array $rankedIdentifiers): array
    {
        $byIdentifier = [];
        foreach ($candidates as $candidate) {
            $byIdentifier[$candidate['identifier']] = $candidate;
        }

        $reranked = [];
        foreach ($rankedIdentifiers as $rank => $identifier) {
            if (isset($byIdentifier[$identifier])) {
                $entry = $byIdentifier[$identifier];
                $entry['score'] = 1.0 / ($rank + 1); // Replace score with rank-based value
                $reranked[] = $entry;
                unset($byIdentifier[$identifier]);
            }
        }

        // Append any candidates the LLM omitted, preserving their original order
        foreach ($byIdentifier as $leftover) {
            $reranked[] = $leftover;
        }

        return $reranked;
    }
}
