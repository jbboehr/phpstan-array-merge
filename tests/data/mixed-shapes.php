<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<array{fixed: int}, array<string, string>> $constantFirst */
assertType('non-empty-array<string, int|string>', $constantFirst);

/** @phpstan-var array-merge<array<string, string>, array{fixed: int}> $constantLast */
assertType('non-empty-array<string, int|string>', $constantLast);

/** @phpstan-var array-merge<array{fixed?: int}, array<string, string>> $possiblyEmpty */
assertType('array<string, int|string>', $possiblyEmpty);

/** @phpstan-var array-merge<array{2: int}, array<int, string>> $numericKeys */
assertType('non-empty-list<int|string>', $numericKeys);

/** @phpstan-var array-merge<array{a: int}|array{b: string}, array<string, bool>> $unionedShapes */
assertType('non-empty-array<string, bool|int|string>', $unionedShapes);

/** @phpstan-var array-merge<array{1}|array{2, 3}, array<int, string>> $unionedLists */
assertType('non-empty-list<1|2|3|string>', $unionedLists);

/** @phpstan-var array-merge<array<int, 'left'>|array<string, 'right'>> $unionedGenericArrays */
assertType("array<int<0, max>|string, 'left'|'right'>", $unionedGenericArrays);

/** @phpstan-var array-merge<array{}|array{a: int}, array<string, bool>> $possiblyEmptyUnion */
assertType('array<string, bool|int>', $possiblyEmptyUnion);
