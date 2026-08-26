<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<array{int}, array<int, string>> $constantFirst */
assertType('non-empty-list<int|string>', $constantFirst);

/** @phpstan-var array-merge<array<int, string>, array{int}> $constantLast */
assertType('non-empty-list<int|string>', $constantLast);

/** @phpstan-var array-merge<array{0?: int}, array<int, string>> $possiblyEmpty */
assertType('list<int|string>', $possiblyEmpty);

/** @phpstan-var array-merge<array{}, array<int, string>> $emptyFirst */
assertType('list<string>', $emptyFirst);

/** @phpstan-var array-merge<array<int, string>, array{}> $emptyLast */
assertType('list<string>', $emptyLast);
