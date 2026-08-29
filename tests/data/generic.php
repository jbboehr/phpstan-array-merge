<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\genericMergeOne;
use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\genericMergeUnionOperand;
use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\genericMergeUnionOperandWithImpossibleShape;
use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\identity;
use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\sanity1;
use function PHPStan\Testing\assertType;

/** sanity check, type inferrence when the function is in this file does not seem to work WTF */
assertType("array<string, stdClass>", sanity1());

assertType("array{a: 1}", identity(['a' => 1]));

assertType("array{a: 1, b: 2}", genericMergeOne(['a' => 1], ['b' => 2]));

assertType("array{a: 2}", genericMergeOne(['a' => 1], ['a' => 2]));

/** @phpstan-var array{a: int} $templateUnionInput */
$templateUnionInput = ['a' => 1];
assertType("array{a?: int, c?: bool}", genericMergeUnionOperand($templateUnionInput));

/**
 * @template T of array{}|array{a: int}
 * @param T $value
 */
function assertImpossibleShapeDoesNotEraseTemplateUnion(array $value): void
{
    assertType("array<string, int>", genericMergeUnionOperandWithImpossibleShape($value));
}
