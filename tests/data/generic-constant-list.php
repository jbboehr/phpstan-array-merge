<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\genericMergeOne;
use function PHPStan\Testing\assertType;

assertType("array{1, 2}", genericMergeOne([1], [2]));

/** @phpstan-var array-merge<array{}> $empty */
assertType('array{}', $empty);

/** @phpstan-var array-merge<array{}, array{1}> $emptyFirst */
assertType('array{1}', $emptyFirst);

/** @phpstan-var array-merge<array{1}, array{}> $emptyLast */
assertType('array{1}', $emptyLast);
