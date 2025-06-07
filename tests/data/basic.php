<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<array{a: 1}, array{b: 2}> $a */
assertType("array{a: 1, b: 2}", $a);

/** @phpstan-var array-merge<array<string, string>, array<string, string>> $b */
assertType("array<string, string>", $b);

/** @phpstan-var array-merge<array<mixed>, array<string>> $b */
assertType("array<mixed>", $b);

/** @phpstan-var array-merge<array{b: 1}, array{b: 2}> $a */
assertType("array{b: 2}", $a);
