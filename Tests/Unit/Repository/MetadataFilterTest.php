<?php

declare(strict_types=1);

namespace BoehmMatthias\SmartSearch\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use BoehmMatthias\SmartSearch\Repository\MetadataFilter;

final class MetadataFilterTest extends TestCase
{
    #[Test]
    public function anEmptyFilterSetMatchesEverything(): void
    {
        self::assertTrue(MetadataFilter::matches([], []));
        self::assertTrue(MetadataFilter::matches(['a' => 1], []));
    }

    #[Test]
    public function allFiltersMustMatch(): void
    {
        $metadata = ['sys_language_uid' => 1, 'site' => 'main'];

        self::assertTrue(MetadataFilter::matches($metadata, ['sys_language_uid' => 1]));
        self::assertTrue(MetadataFilter::matches($metadata, ['sys_language_uid' => 1, 'site' => 'main']));
        self::assertFalse(MetadataFilter::matches($metadata, ['sys_language_uid' => 1, 'site' => 'other']));
    }

    #[Test]
    public function anAbsentKeyNeverMatches(): void
    {
        self::assertFalse(MetadataFilter::matches(['a' => 1], ['b' => 1]));
    }

    /**
     * The bug this class exists for. Under PHP's loose comparison a bool operand casts the
     * other side to bool, so a "published" filter matched essentially any non-empty value.
     *
     * @return array<string, array{scalar}>
     */
    public static function truthyNonBooleanValues(): array
    {
        return [
            'string no' => ['no'],
            'string zero-point-zero' => ['0.0'],
            'string anything' => ['anything'],
            'int one' => [1],
            'int forty-two' => [42],
        ];
    }

    #[Test]
    #[DataProvider('truthyNonBooleanValues')]
    public function aBooleanTrueFilterDoesNotMatchMerelyTruthyValues(string|int $stored): void
    {
        self::assertFalse(MetadataFilter::matches(['published' => $stored], ['published' => true]));
    }

    /**
     * @return array<string, array{scalar}>
     */
    public static function falsyNonBooleanValues(): array
    {
        return [
            'int zero' => [0],
            'empty string' => [''],
            'string zero' => ['0'],
        ];
    }

    #[Test]
    #[DataProvider('falsyNonBooleanValues')]
    public function aBooleanFalseFilterDoesNotMatchMerelyFalsyValues(string|int $stored): void
    {
        self::assertFalse(MetadataFilter::matches(['published' => $stored], ['published' => false]));
    }

    #[Test]
    public function booleansStillMatchThemselves(): void
    {
        self::assertTrue(MetadataFilter::matches(['published' => true], ['published' => true]));
        self::assertTrue(MetadataFilter::matches(['published' => false], ['published' => false]));
        self::assertFalse(MetadataFilter::matches(['published' => true], ['published' => false]));
    }

    #[Test]
    public function numericStringsAreNotComparedNumerically(): void
    {
        // '007' == '7' and '1e3' == '1000' are both true under loose comparison.
        self::assertFalse(MetadataFilter::matches(['site' => '007'], ['site' => '7']));
        self::assertFalse(MetadataFilter::matches(['site' => '1e3'], ['site' => '1000']));
        self::assertTrue(MetadataFilter::matches(['site' => '007'], ['site' => '007']));
    }

    #[Test]
    public function aStringDoesNotMatchTheEquivalentInteger(): void
    {
        self::assertFalse(MetadataFilter::matches(['sys_language_uid' => 1], ['sys_language_uid' => '1']));
        self::assertFalse(MetadataFilter::matches(['sys_language_uid' => '1'], ['sys_language_uid' => 1]));
    }

    #[Test]
    public function intAndFloatAreTreatedAsOneNumericDomain(): void
    {
        // json_decode can hand back either depending on how the value was written.
        self::assertTrue(MetadataFilter::matches(['n' => 1], ['n' => 1.0]));
        self::assertTrue(MetadataFilter::matches(['n' => 1.0], ['n' => 1]));
        self::assertFalse(MetadataFilter::matches(['n' => 1], ['n' => 1.5]));
    }

    #[Test]
    public function aStoredNullDoesNotMatchANonNullFilter(): void
    {
        // The stored side may contain null even though upsert() documents scalar values
        // only, because it is whatever json_decode() returned for the column. Filtering
        // *for* null is not expressible — see the note on MetadataFilter::matches().
        self::assertFalse(MetadataFilter::matches(['author' => null], ['author' => 'jo']));
        self::assertFalse(MetadataFilter::matches(['author' => null], ['author' => 0]));
        self::assertFalse(MetadataFilter::matches(['author' => null], ['author' => false]));
    }
}
