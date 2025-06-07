<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\nested;
use function PHPStan\Testing\assertType;

assertType("array{a: 1, b: 2, c: 3}", nested(['a' => 1], ['b' => 2], ['c' => 3]));
