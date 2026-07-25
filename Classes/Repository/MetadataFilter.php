<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Repository;

/**
 * Decides whether a stored metadata array satisfies a set of filters.
 *
 * Extracted from VectorRepository so the comparison rules are testable without a database.
 * They are worth testing: metadata filters are the only thing separating languages, sites or
 * tenants sharing a collection, so a filter that matches too much leaks content across that
 * boundary with no error and no log line.
 *
 * Values arrive from json_decode(), which preserves int, float, bool and string, so the
 * comparison is strict. The previous loose `!=` inherited PHP's coercion rules, under which a
 * bool operand casts the other side to bool — making ['published' => true] match stored "no",
 * "0.0", 42 and "anything".
 */
final class MetadataFilter
{
    /**
     * $metadata is whatever json_decode() returned for the stored column, so it may contain
     * null even though upsert() documents scalar values only. $filters follows the documented
     * caller contract. Filtering *for* null is therefore not expressible — a deliberate
     * limitation, not an oversight.
     *
     * @param array<string, scalar|null> $metadata
     * @param array<string, scalar> $filters
     */
    public static function matches(array $metadata, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            // array_key_exists rather than isset: a key present with a null value is a
            // legitimately stored value, not an absent one.
            if (!array_key_exists($key, $metadata)) {
                return false;
            }

            if (!self::valuesMatch($metadata[$key], $expected)) {
                return false;
            }
        }

        return true;
    }

    private static function valuesMatch(mixed $stored, mixed $expected): bool
    {
        // int and float are treated as one numeric domain, so a filter of 1 matches a value
        // stored as 1.0. Everything else — bool, string, null — must match exactly, which is
        // what stops "1" matching 1 and true matching everything truthy.
        $bothNumeric = (is_int($stored) || is_float($stored))
            && (is_int($expected) || is_float($expected));

        if ($bothNumeric) {
            return (float) $stored === (float) $expected;
        }

        return $stored === $expected;
    }
}
