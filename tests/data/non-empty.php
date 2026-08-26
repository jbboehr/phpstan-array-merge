<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<non-empty-array<int, 'required'>, array<int, 'optional'>> $nonEmptyFirst */
assertType("non-empty-list<'optional'|'required'>", $nonEmptyFirst);

/** @phpstan-var array-merge<array<int, 'optional'>, non-empty-array<int, 'required'>> $nonEmptyLast */
assertType("non-empty-list<'optional'|'required'>", $nonEmptyLast);

/** @phpstan-var array-merge<non-empty-array<string, int>> $stringKeys */
assertType('non-empty-array<string, int>', $stringKeys);
