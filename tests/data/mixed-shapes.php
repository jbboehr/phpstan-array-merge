<?php

namespace jbboehr\PHPStan\ArrayMerge\Tests\Data;

use function array_flip;
use function array_values;
use function PHPStan\Testing\assertType;

/** @phpstan-var array-merge<array{fixed: int}, array<string, string>> $constantFirst */
assertType('non-empty-array<string, int|string>', $constantFirst);

/** @phpstan-var array-merge<array<string, string>, array{fixed: int}> $constantLast */
assertType('non-empty-array<string, int|string>', $constantLast);

/** @phpstan-var array-merge<array<string, int>, array{fixed: string}> $constantLastWithKnownOffset */
assertType('non-empty-array<string, int|string>', $constantLastWithKnownOffset);
assertType('int|string', array_values($constantLastWithKnownOffset)[0]);

/** @phpstan-var array-merge<array<string, int>, array<string, bool>, array{fixed: string}> $multipleGenericArraysFirst */
assertType('non-empty-array<string, bool|int|string>', $multipleGenericArraysFirst);
assertType('bool|int|string', array_values($multipleGenericArraysFirst)[0]);

/** @phpstan-var array-merge<array<string, int>, array{first: string}, array{second: bool}> $multipleConstantsLast */
assertType('non-empty-array<string, bool|int|string>', $multipleConstantsLast);

/** @phpstan-var array-merge<array<string, int>, array{fixed: string}, array{fixed: bool}> $multipleConstantsLastWriteWins */
assertType('non-empty-array<string, bool|int|string>', $multipleConstantsLastWriteWins);

/** @phpstan-var array-merge<array<string, string>, array{fixed: 'x'}> $constantValueLast */
assertType('non-empty-array<string, string>', array_flip($constantValueLast));

/** @phpstan-var array-merge<array<string, string>, array{fixed?: int}> $optionalConstantLast */
assertType('array<string, int|string>', $optionalConstantLast);

/** @phpstan-var array-merge<array{fixed?: int}, array<string, string>> $possiblyEmpty */
assertType('array<string, int|string>', $possiblyEmpty);

/** @phpstan-var array-merge<array{2: int}, array<int, string>> $numericKeys */
assertType('non-empty-list<int|string>', $numericKeys);

/** @phpstan-var array-merge<array{a: int}|array{b: string}, array<string, bool>> $unionedShapes */
assertType('non-empty-array<string, bool|int|string>', $unionedShapes);

/** @phpstan-var array-merge<array{1}|array{2, 3}, array<int, string>> $unionedLists */
assertType('non-empty-list<1|2|3|string>', $unionedLists);

/** @phpstan-var array-merge<array<int, 'left'>|array<string, 'right'>> $unionedGenericArrays */
assertType("array<int<0, max>|string, 'left'|'right'>", $unionedGenericArrays);

/** @phpstan-var array-merge<array{}|array{a: int}, array<string, bool>> $possiblyEmptyUnion */
assertType('array<string, bool|int>', $possiblyEmptyUnion);

/** @phpstan-var array-merge<array{a: int}|never> $arrayWithNeverAlternative */
assertType('array{a: int}', $arrayWithNeverAlternative);

/** @phpstan-var array-merge<array<int, string>|int> $maybeList */
assertType('*ERROR*', $maybeList);

/** @phpstan-var array-merge<array<string, int>|string> $maybeStringArray */
assertType('*ERROR*', $maybeStringArray);

/** @phpstan-var array-merge<array<never, string>|int> $maybeEmptyArray */
assertType('*ERROR*', $maybeEmptyArray);

/** @phpstan-var array-merge<array<never, string>> $emptyArray */
assertType('array{}', $emptyArray);

/** @phpstan-var array-merge<array<string, never>|int> $maybeEmptyValueArray */
assertType('*ERROR*', $maybeEmptyValueArray);

/** @phpstan-var array-merge<array<string, never>> $emptyValueArray */
assertType('array{}', $emptyValueArray);

/** @phpstan-var array-merge<non-empty-array<string, never>> $impossibleValueArray */
assertType('*NEVER*', $impossibleValueArray);

/** @phpstan-var array-merge<non-empty-array<never, string>> $impossibleKeyArray */
assertType('*NEVER*', $impossibleKeyArray);

/** @phpstan-var array-merge<array{required: never, other: int}> $impossibleRequiredOffset */
assertType('*NEVER*', $impossibleRequiredOffset);

/** @phpstan-var array-merge<array{bad: never}|array{good: int}> $partiallyImpossibleShape */
assertType('array{good: int}', $partiallyImpossibleShape);

/** @phpstan-var array-merge<array{bad: never}|array{good: int}, array{tail: string}> $partiallyImpossibleThenTail */
assertType('array{good: int, tail: string}', $partiallyImpossibleThenTail);

/** @phpstan-var array-merge<array{bad: never}|array{first: int}|array{second: string}> $impossibleAmongMultipleSurvivors */
assertType('array{first?: int, second?: string}', $impossibleAmongMultipleSurvivors);

/** @phpstan-var array-merge<array{first: never}|array{second: never}, array{tail: string}> $allImpossibleShapesThenTail */
assertType('*NEVER*', $allImpossibleShapesThenTail);

/** @phpstan-var array-merge<array{optional?: never, kept: int}|array{other: string}> $optionalNeverAmongSurvivors */
assertType('array{kept?: int, other?: string}', $optionalNeverAmongSurvivors);

/** @phpstan-var array-merge<array{optional?: never, kept: int}> $optionalNeverWithRequiredOffset */
assertType('array{kept: int}', $optionalNeverWithRequiredOffset);

/** @phpstan-var array-merge<array{removed?: never, kept?: int}> $optionalNeverWithOptionalOffset */
assertType('array{kept?: int}', $optionalNeverWithOptionalOffset);

/** @phpstan-var array-merge<array{outer: array{optional?: never, kept: int}}, array{tail: string}> $nestedOptionalNever */
assertType('array{outer: array{kept: int}, tail: string}', $nestedOptionalNever);

/** @phpstan-var array-merge<array{removed?: array{required: never}, kept: int}> $optionalNormalizedNever */
assertType('array{kept: int}', $optionalNormalizedNever);

/** @phpstan-var array-merge<array{0: string, removed?: never, kept: int}> $optionalNeverWithIntegerKey */
assertType('array{0: string, removed?: never, kept: int}', $optionalNeverWithIntegerKey);

/** @phpstan-var array-merge<array{0: string, removed?: array{required: never}, kept: int}> $normalizedOptionalNeverWithIntegerKey */
assertType('array{0: string, removed?: *NEVER*, kept: int}', $normalizedOptionalNeverWithIntegerKey);

/** @phpstan-var array-merge<array{0: string, bad: never, ...<string, bool>}> $requiredNeverWithExcludedShapeKinds */
assertType('*NEVER*', $requiredNeverWithExcludedShapeKinds);

/** @phpstan-var array-merge<array{outer: array{removed?: never, kept: int, ...<string, bool>}}> $nestedUnsealedShape */
assertType('array{outer: array{removed?: never, kept: int, ...<string, bool>}}', $nestedUnsealedShape);

/** @phpstan-var array-merge<array{bad: never}|array<int, string>> $impossibleShapeOrGenericList */
assertType('list<string>', $impossibleShapeOrGenericList);

/** @phpstan-var array-merge<array{outer: array{bad: never}}|array<int, string>> $nestedImpossibleShapeOrGenericList */
assertType('list<string>', $nestedImpossibleShapeOrGenericList);

/** @phpstan-var array-merge<array{optional?: never}> $optionalNeverOffset */
assertType('array{}', $optionalNeverOffset);
