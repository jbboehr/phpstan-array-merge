<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use jbboehr\PHPStan\ArrayMerge\Tests\Fixture\MultiConstVar;
use function PHPStan\Testing\assertType;

$var = (new MultiConstVar())->var;
assertType("array{foo: 'bar', baz: 'bat'}", $var);
