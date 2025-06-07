<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge $a */
assertType("*ERROR*", $a);
