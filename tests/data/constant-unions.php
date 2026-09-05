<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function array_keys;
use function array_values;
use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<array{a: 1}|array{b: 2}> $shape */
assertType('array{a?: 1, b?: 2}', $shape);

/** @phpstan-var array-merge<array{a: 1}|array{b: 2}, array{a: 3}> $overwrittenShape */
// The b-only branch inserts b before a, while the a-only branch starts with a.
assertType("non-empty-array<'a'|'b', 1|2|3>", $overwrittenShape);
assertType('1|2|3', array_values($overwrittenShape)[0]);
assertType("'a'|'b'", array_keys($overwrittenShape)[0]);

/** @phpstan-var array-merge<array{1}|array{2, 3}, array{4}> $list */
assertType('array{0: 1|2, 1: 3|4, 2?: 4}', $list);

/** @phpstan-var array-merge<array{}|array{5}, array{6}> $possiblyEmptyList */
assertType('array{0: 5|6, 1?: 6}', $possiblyEmptyList);

/** @phpstan-var array-merge<array{2: 'a', 1: 'b'}|array{1: 'c', 2: 'd'}> $reorderedIntegerKeys */
assertType("array{'a'|'c', 'b'|'d'}", $reorderedIntegerKeys);
