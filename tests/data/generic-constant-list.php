<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\genericMergeOne;
use function PHPStan\Testing\assertType;

assertType("array{1, 2}", genericMergeOne([1], [2]));
