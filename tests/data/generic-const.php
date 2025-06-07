<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use jbboehr\PHPStan\ArrayMerge\Tests\Fixture\ConstFixture;
use function PHPStan\Testing\assertType;

assertType("array{foo: 'bar', baz: 'bat'}", ConstFixture::constMerge(['baz' => 'bat']));
