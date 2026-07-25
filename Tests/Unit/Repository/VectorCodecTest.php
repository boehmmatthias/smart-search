<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BoehmMatthias\SmartSearch\Repository\VectorCodec;

final class VectorCodecTest extends TestCase
{
    #[Test]
    public function roundTripPreservesValuesWithinFloat32Precision(): void
    {
        $vector = [0.1, -0.42, 0.87654321, 0.0, 1.0, -1.0, 0.001];

        $result = VectorCodec::unpack(VectorCodec::pack($vector));

        self::assertCount(count($vector), $result);
        foreach ($vector as $index => $expected) {
            self::assertEqualsWithDelta($expected, $result[$index], 1.0e-6);
        }
    }

    #[Test]
    public function roundTripPreservesOrder(): void
    {
        $vector = [0.9, 0.8, 0.7, 0.6, 0.5];

        $result = VectorCodec::unpack(VectorCodec::pack($vector));

        // Ordering is load-bearing: a reordered vector still scores, just wrongly.
        $sorted = $result;
        rsort($sorted);
        self::assertSame($sorted, $result);
    }

    #[Test]
    public function roundTripHandlesRealisticEmbeddingDimensions(): void
    {
        $vector = array_map(static fn(int $i): float => sin($i) / 2, range(1, 3072));

        $packed = VectorCodec::pack($vector);
        $result = VectorCodec::unpack($packed);

        self::assertSame(3072 * 4, strlen($packed));
        self::assertCount(3072, $result);
        self::assertEqualsWithDelta($vector[3071], $result[3071], 1.0e-6);
    }

    #[Test]
    public function packingAnEmptyVectorProducesAnEmptyStringThatRoundTrips(): void
    {
        self::assertSame('', VectorCodec::pack([]));
        self::assertSame([], VectorCodec::unpack(''));
    }

    #[Test]
    public function unpackRejectsLegacyJsonTextInsteadOfDecodingItAsFloats(): void
    {
        // Exactly what a row written before the packed-binary format still contains. Its
        // length here is a multiple of 4, so the length check alone would not catch it.
        $legacy = '[0.1,0.2,0.3,-0.45,0.5,0.61]';
        self::assertSame(0, strlen($legacy) % 4, 'fixture must defeat the length check');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_004_001);

        VectorCodec::unpack($legacy);
    }

    #[Test]
    public function unpackRejectsBlobsWhoseLengthIsNotAMultipleOfFour(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1_700_004_002);

        // unpack('f*') would silently drop the trailing byte and return one float.
        VectorCodec::unpack('abcde');
    }
}
