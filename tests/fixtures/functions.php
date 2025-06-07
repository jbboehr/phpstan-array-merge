<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Fixture;

/**
 * @template A of array<mixed>
 * @template B of array<mixed>
 * @param A $a
 * @param B $b
 * @phpstan-return array-merge<A, B>
 */
function genericMergeOne(array $a, array $b): array
{
    return [];
}

/**
 * @template A of array<mixed>
 * @param A $a
 * @phpstan-return array-merge<A>
 */
function identity(array $a): array
{
    return [];
}

/**
 * @return array<string, \stdClass>
 */
function sanity1(): array
{
    return [];
}

class ConstFixture
{
    public const ARRAY = ['foo' => 'bar'];

    /**
     * @template T of array<mixed>
     * @param T $a
     * @phpstan-return array-merge<self::ARRAY, T>
     */
    public static function constMerge(array $a): array
    {
        return array_merge(self::ARRAY, $a);
    }
}
