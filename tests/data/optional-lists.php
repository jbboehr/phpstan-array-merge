<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<array{0: 1, 1?: 2}> $identity */
assertType('array{0: 1, 1?: 2}', $identity);

/** @phpstan-var array-merge<array{0: 1, 1?: 2}, array{0: 3}> $followedByRequired */
assertType('array{0: 1, 1: 2|3, 2?: 3}', $followedByRequired);

/** @phpstan-var array-merge<array{0: 1, 1?: 2}, array{0: 3, 1?: 4}> $twoOptionalTails */
assertType('list{0: 1, 1: 2|3, 2?: 3|4, 3?: 4}', $twoOptionalTails);

/** @phpstan-var array-merge<array{0?: 1}> $optionalOnly */
assertType('array{0?: 1}', $optionalOnly);

/** @phpstan-var array-merge<array{0?: 1}, array{0: 2}> $optionalOnlyThenRequired */
assertType('array{0: 1|2, 1?: 2}', $optionalOnlyThenRequired);

/** @phpstan-var array-merge<array{0: 1, 1?: 2, 2?: 3}> $longOptionalSuffix */
assertType('list{0: 1, 1?: 2|3, 2?: 3}', $longOptionalSuffix);
