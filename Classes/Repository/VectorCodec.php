<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Repository;

/**
 * Encodes and decodes vectors as packed IEEE 754 single-precision (float32) binary.
 * ~4 bytes per dimension vs ~8–14 bytes per dimension in JSON.
 *
 * Extracted from VectorRepository so the round trip is testable without a database.
 *
 * Note that pack('f*') uses native byte order and native float size. A database dumped
 * on one architecture and restored on another with different endianness decodes to
 * garbage. Making this deterministic means switching to 'g*'/'e*' and treating it as a
 * storage format change requiring migration.
 */
final class VectorCodec
{
    private const BYTES_PER_FLOAT = 4;

    /**
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /**
     * Decodes a packed float32 binary string back to a float array.
     *
     * Rejects input that cannot be packed float32 rather than returning a plausible-looking
     * wrong answer. Two cases matter:
     *
     *  - A byte length that is not a multiple of 4. unpack('f*') does NOT fail here — it
     *    integer-divides and silently discards the remainder, so unpack('f*', 'abcde')
     *    yields one float and drops a byte.
     *  - A leading '[', which means the column still holds the JSON text used before the
     *    packed-binary format. Those bytes decode to finite, positive, entirely plausible
     *    floats of the wrong dimension, so nothing downstream can tell them apart from a
     *    vector produced by a different embedding model.
     *
     * @return float[]
     * @throws \RuntimeException if the input is not a packed float32 vector
     */
    public static function unpack(string $binary): array
    {
        if ($binary === '') {
            return [];
        }

        if (str_starts_with($binary, '[')) {
            throw new \RuntimeException(
                'Vector column holds JSON text, not packed float32 binary. This row predates the '
                . 'packed-binary storage format and must be migrated before it can be searched.',
                1_700_004_001,
            );
        }

        if (strlen($binary) % self::BYTES_PER_FLOAT !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'Vector blob is %d bytes, which is not a multiple of %d — it is corrupt or not '
                    . 'packed float32 binary.',
                    strlen($binary),
                    self::BYTES_PER_FLOAT,
                ),
                1_700_004_002,
            );
        }

        return array_values((array) unpack('f*', $binary));
    }

    /**
     * True if the value is a vector in the pre-0.2.0 JSON text format.
     *
     * A JSON float array always starts with '[', which packed float32 output effectively
     * never does — that would need a first component whose low byte is 0x5B followed by three
     * more bytes spelling a plausible prefix.
     */
    public static function isLegacyJson(string $binary): bool
    {
        return str_starts_with($binary, '[');
    }

    /**
     * Re-encodes a pre-0.2.0 JSON float array as packed float32.
     *
     * Lossless with respect to the target format: pack('f*') would have narrowed these same
     * float64 values to float32 on write anyway, so the result is identical to what storing
     * the vector today would produce.
     *
     * @throws \RuntimeException if the value is not a usable JSON float array
     */
    public static function packLegacyJson(string $json): string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                sprintf('Legacy vector is not valid JSON: %s', $e->getMessage()),
                1_700_004_003,
                $e,
            );
        }

        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException(
                'Legacy vector JSON is not a non-empty array.',
                1_700_004_004,
            );
        }

        $floats = [];
        foreach ($decoded as $component) {
            if (!is_int($component) && !is_float($component)) {
                throw new \RuntimeException(
                    'Legacy vector JSON contains a non-numeric component.',
                    1_700_004_005,
                );
            }
            $floats[] = (float) $component;
        }

        return self::pack($floats);
    }
}
