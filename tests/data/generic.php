<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/**
 * @template A of array
 * @template B of array
 * @param A $a
 * @param B $b
 * @phpstan-return array-merge<A, B>
 */
function merge(array $a, array $b): array
{
    return array_merge($a, $b);
}

$b = merge(['a' => 1], ['b' => 2]);
assertType("array{a: 1, b: 2}", $b);
