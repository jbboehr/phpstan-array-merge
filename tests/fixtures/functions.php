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
 * @return array<string, \stdClass>
 */
function sanity1(): array
{
    return [];
}
