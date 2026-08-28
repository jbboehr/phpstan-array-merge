<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function array_merge;
use function jbboehr\PHPStan\ArrayMerge\Tests\Fixture\genericMergeOne;
use function PHPStan\Testing\assertType;

assertType('array{same: 2}', array_merge(['same' => 1], ['same' => 2]));
assertType('array{same: 2}', genericMergeOne(['same' => 1], ['same' => 2]));

assertType("array{'first', 'second'}", array_merge([2 => 'first'], [2 => 'second']));
assertType("array{'first', 'second'}", genericMergeOne([2 => 'first'], [2 => 'second']));

/** @var array{0: 1, 1?: 2} $optionalList */
/** @var array{0: 3} $requiredList */
assertType('array{0: 1, 1: 2|3, 2?: 3}', array_merge($optionalList, $requiredList));
assertType('array{0: 1, 1: 2|3, 2?: 3}', genericMergeOne($optionalList, $requiredList));

/** @var array<string, int> $genericIntegers */
/** @var array<string, string> $genericStrings */
assertType('array<string, int|string>', array_merge($genericIntegers, $genericStrings));
assertType('array<string, int|string>', genericMergeOne($genericIntegers, $genericStrings));

/** @var array<string, 'left'>|array<string, 'right'> $genericArrayUnion */
/** @var array<string, 'tail'> $genericArrayTail */
assertType("array<string, 'left'|'right'|'tail'>", array_merge($genericArrayUnion, $genericArrayTail));
assertType("array<string, 'left'|'right'|'tail'>", genericMergeOne($genericArrayUnion, $genericArrayTail));
