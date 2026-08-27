<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge $a */
assertType("*ERROR*", $a);

/** @phpstan-var array-merge<int> $nonArray */
assertType("*ERROR*", $nonArray);

/** @phpstan-var array-merge<array{}, string> $partiallyInvalid */
assertType("*ERROR*", $partiallyInvalid);

/** @phpstan-var array-merge<non-empty-array<string, never>, int> $impossibleThenInvalid */
assertType("*ERROR*", $impossibleThenInvalid);

/** @phpstan-var array-merge<int, non-empty-array<string, never>> $invalidThenImpossible */
assertType("*ERROR*", $invalidThenImpossible);

/** @phpstan-var array-merge<mixed> $maybeArray */
assertType("array<mixed, mixed>", $maybeArray);
