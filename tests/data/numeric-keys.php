<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<array{2: 'value'}> $sparse */
assertType("array{'value'}", $sparse);

/** @phpstan-var array-merge<array{2: 'first'}, array{2: 'second'}> $duplicate */
assertType("array{'first', 'second'}", $duplicate);

/** @phpstan-var array-merge<array{name: 1, 3: 2}, array{7: 3, name: 4}> $mixed */
assertType('array{name: 4, 0: 2, 1: 3}', $mixed);

/** @phpstan-var array-merge<array<int<5, 10>, string>> $bounded */
assertType('array<int<0, max>, string>', $bounded);

/** @phpstan-var array-merge<array<int|string, string>> $genericMixed */
assertType('array<int<0, max>|string, string>', $genericMixed);

/** @phpstan-var array-merge<array<int, string>> $unbounded */
assertType('array<int<0, max>, string>', $unbounded);

/** @phpstan-var array-merge<array{0: 'a', 2: 'b'}> $hole */
assertType("array{'a', 'b'}", $hole);

/** @phpstan-var array-merge<array{-1: 'a'}> $negative */
assertType("array{'a'}", $negative);

/** @phpstan-var array-merge<array{'08': 'a', 2: 'b'}> $numericString */
assertType("array{'08': 'a', 0: 'b'}", $numericString);
