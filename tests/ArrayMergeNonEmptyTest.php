<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXVI John Boehr & contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace jbboehr\PHPStan\ArrayMerge\Tests;

use jbboehr\PHPStan\ArrayMerge\ArrayMergeType;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use function array_merge;
use function count;
use function is_int;
use function sprintf;

final class ArrayMergeNonEmptyTest extends PHPStanTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }

    /** @return iterable<string, array{string}> */
    public static function nonEmptyProvider(): iterable
    {
        yield 'single union of nonempty shapes' => [
            'array-merge<array{a: 1}|array{b: 2}>',
        ];
        yield 'nonempty union before an optional shape' => [
            'array-merge<array{a: 1}|array{b: 2}, array{c?: 3}>',
        ];
        yield 'nonempty union after an optional shape' => [
            'array-merge<array{c?: 3}, array{a: 1}|array{b: 2}>',
        ];
        yield 'nonempty intersection on a single shape' => [
            'array-merge<array{a?: 1, b?: 2}&non-empty-array>',
        ];
        yield 'nonempty intersection after an empty array' => [
            'array-merge<array{}, array{a?: 1, b?: 2}&non-empty-array>',
        ];
        yield 'nonempty intersection survives integer-key reindexing' => [
            'array-merge<array{2?: 1, 7?: 2}&non-empty-array>',
        ];
        yield 'nonempty generic operand before a possibly empty operand' => [
            'array-merge<non-empty-array<string, int>, array<string, int>>',
        ];
        yield 'nonempty generic operand after a possibly empty operand' => [
            'array-merge<array<string, int>, non-empty-array<string, int>>',
        ];
    }

    #[DataProvider('nonEmptyProvider')]
    public function testPreservesGuaranteedNonemptiness(string $phpDoc): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($phpDoc);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();
        $empty = ConstantArrayTypeBuilder::createEmpty()->getArray();

        foreach ([$result, $result->getKeysArray(), $result->getValuesArray()] as $type) {
            $description = $phpDoc . ' resolved to ' . $type->describe(VerbosityLevel::precise());
            $this->assertTrue($type->isIterableAtLeastOnce()->yes(), $description);
            $this->assertTrue($type->isSuperTypeOf($empty)->no(), $description);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function possiblyEmptyProvider(): iterable
    {
        yield 'all optional keys' => ['array-merge<array{a?: 1, b?: 2}>'];
        yield 'one empty union branch' => ['array-merge<array{}|array{a: 1}>'];
        yield 'independent possibly empty unions' => [
            'array-merge<array{}|array{a: 1}, array{}|array{b: 2}>',
        ];
        yield 'possible empty survivor beside an impossible branch' => [
            'array-merge<array{}|array{bad: never}|array{a: 1}>',
        ];
        yield 'serialized benevolent union with conflicting key orders' => [
            'array-merge<__array_merge_benevolent<array{}|array{a: 1, b: 2}|array{b: 2, a: 1}>>',
        ];
    }

    #[DataProvider('possiblyEmptyProvider')]
    public function testPreservesPossibleEmptyOutcome(string $phpDoc): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($phpDoc);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();
        $empty = ConstantArrayTypeBuilder::createEmpty()->getArray();

        $this->assertTrue($result->isIterableAtLeastOnce()->maybe(), $phpDoc);
        $this->assertTrue($result->isSuperTypeOf($empty)->yes(), $phpDoc);
    }

    public function testNonemptyClassificationMatchesEveryConcreteUnionOutcome(): void
    {
        $runtimeArrays = [
            'empty' => [],
            'string-a' => ['a' => 1],
            'string-b' => ['b' => 2],
            'opposite-order' => ['b' => 3, 'a' => 4],
            'integer' => [5],
        ];
        $arrayTypes = [];
        foreach ($runtimeArrays as $name => $runtimeArray) {
            $arrayTypes[$name] = self::constantArrayFromRuntime($runtimeArray);
        }

        $alternativeSets = [
            'empty' => ['empty'],
            'required string' => ['string-a'],
            'required integer' => ['integer'],
            'required opposite order' => ['opposite-order'],
            'possibly empty string' => ['empty', 'string-a'],
            'required string union' => ['string-a', 'string-b'],
            'required mixed-key union' => ['string-a', 'integer'],
            'possibly empty mixed-key union' => ['empty', 'string-a', 'integer'],
        ];

        foreach ($alternativeSets as $leftName => $leftAlternatives) {
            foreach ($alternativeSets as $rightName => $rightAlternatives) {
                $leftType = self::unionOf($arrayTypes, $leftAlternatives);
                $rightType = self::unionOf($arrayTypes, $rightAlternatives);
                $result = (new ArrayMergeType([$leftType, $rightType]))->resolve();
                $case = sprintf(
                    '%s then %s resolved to %s',
                    $leftName,
                    $rightName,
                    $result->describe(VerbosityLevel::precise()),
                );
                $hasEmptyOutcome = false;
                $hasNonEmptyOutcome = false;

                foreach ($leftAlternatives as $leftAlternative) {
                    foreach ($rightAlternatives as $rightAlternative) {
                        $runtimeResult = array_merge(
                            $runtimeArrays[$leftAlternative],
                            $runtimeArrays[$rightAlternative],
                        );
                        $hasEmptyOutcome = $hasEmptyOutcome || [] === $runtimeResult;
                        $hasNonEmptyOutcome = $hasNonEmptyOutcome || [] !== $runtimeResult;

                        $this->assertTrue(
                            $result->isSuperTypeOf(self::constantArrayFromRuntime($runtimeResult))->yes(),
                            $case,
                        );
                    }
                }

                $nonempty = $result->isIterableAtLeastOnce();
                if ($hasEmptyOutcome && $hasNonEmptyOutcome) {
                    $this->assertTrue($nonempty->maybe(), $case);
                } elseif ($hasNonEmptyOutcome) {
                    $this->assertTrue($nonempty->yes(), $case);
                } else {
                    $this->assertTrue($nonempty->no(), $case);
                }
            }
        }
    }

    public function testBenevolentUnionRetainsReachableEmptyAlternative(): void
    {
        $empty = self::constantArrayFromRuntime([]);
        $requiredShape = self::constantArrayFromRuntime(['a' => 1]);
        $operand = new BenevolentUnionType([$empty, $requiredShape]);

        $result = (new ArrayMergeType([$operand]))->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        $this->assertTrue($result->isSuperTypeOf($empty)->yes(), $description);
        $this->assertTrue($result->isIterableAtLeastOnce()->maybe(), $description);
    }

    public function testBenevolentUnionRetainsReachableOptionalShapeAlternative(): void
    {
        $empty = self::constantArrayFromRuntime([]);
        $optionalShape = self::optionalStringShape();
        $requiredShape = self::constantArrayFromRuntime(['b' => 2]);
        $operand = new BenevolentUnionType([$optionalShape, $requiredShape]);

        $result = (new ArrayMergeType([$operand]))->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        $this->assertTrue($result->isSuperTypeOf($empty)->yes(), $description);
        $this->assertTrue($result->isIterableAtLeastOnce()->maybe(), $description);
    }

    public function testBenevolentUnionRetainsEmptyThroughKeyOrderFallback(): void
    {
        $empty = self::constantArrayFromRuntime([]);
        $firstOrder = self::constantArrayFromRuntime(['a' => 1, 'b' => 2]);
        $secondOrder = self::constantArrayFromRuntime(['b' => 2, 'a' => 1]);
        $operand = new BenevolentUnionType([$empty, $firstOrder, $secondOrder]);
        $result = (new ArrayMergeType([$operand]))->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        foreach ([$result, $result->getKeysArray(), $result->getValuesArray()] as $type) {
            $this->assertTrue($type->isSuperTypeOf($empty)->yes(), $description);
            $this->assertTrue($type->isIterableAtLeastOnce()->maybe(), $description);
        }

        $this->assertTrue($result->isSuperTypeOf($firstOrder)->yes(), $description);
        $this->assertTrue($result->isSuperTypeOf($secondOrder)->yes(), $description);
    }

    public function testBenevolentUnionRetainsOptionalShapeThroughKeyOrderFallback(): void
    {
        $empty = self::constantArrayFromRuntime([]);
        $operand = new BenevolentUnionType([
            self::optionalStringShape(),
            self::constantArrayFromRuntime(['a' => 1, 'b' => 2]),
            self::constantArrayFromRuntime(['b' => 2, 'a' => 1]),
        ]);
        $result = (new ArrayMergeType([$operand]))->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        foreach ([$result, $result->getKeysArray(), $result->getValuesArray()] as $type) {
            $this->assertTrue($type->isSuperTypeOf($empty)->yes(), $description);
            $this->assertTrue($type->isIterableAtLeastOnce()->maybe(), $description);
        }
    }

    public function testBenevolentUnionOfNonEmptyGenericArraysRemainsNonEmpty(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $operand = new BenevolentUnionType([
            $resolver->resolve('non-empty-array<string, int>'),
            $resolver->resolve('non-empty-array<int, int>'),
        ]);
        $result = (new ArrayMergeType([$operand]))->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        $this->assertTrue($result->isIterableAtLeastOnce()->yes(), $description);
        $this->assertTrue($result->isSuperTypeOf(self::constantArrayFromRuntime([]))->no(), $description);
        $this->assertTrue($result->isSuperTypeOf(self::constantArrayFromRuntime(['a' => 1]))->yes(), $description);
        $this->assertTrue($result->isSuperTypeOf(self::constantArrayFromRuntime([2]))->yes(), $description);
    }

    public function testSerializedBenevolentUnionRetainsReachableEmptyAlternative(): void
    {
        $merge = self::getContainer()->getByType(TypeStringResolver::class)->resolve(
            'array-merge<__array_merge_benevolent<array{}|array{a: 1}>>',
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $empty = self::constantArrayFromRuntime([]);

        $result = $merge->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        $this->assertTrue($result->isSuperTypeOf($empty)->yes(), $description);
        $this->assertTrue($result->isIterableAtLeastOnce()->maybe(), $description);
    }

    public function testBenevolentUnionOfNonEmptyAlternativesRemainsNonEmpty(): void
    {
        $left = self::constantArrayFromRuntime(['a' => 1]);
        $right = self::constantArrayFromRuntime(['b' => 2]);
        $operand = new BenevolentUnionType([$left, $right]);

        $result = (new ArrayMergeType([$operand]))->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        $this->assertTrue($result->isIterableAtLeastOnce()->yes(), $description);
        $this->assertTrue($result->isSuperTypeOf($left)->yes(), $description);
        $this->assertTrue($result->isSuperTypeOf($right)->yes(), $description);
    }

    public function testSeparateNonEmptyOperandGuaranteesBenevolentMergeNonEmpty(): void
    {
        $empty = self::constantArrayFromRuntime([]);
        $optionalPrefix = new BenevolentUnionType([
            $empty,
            self::constantArrayFromRuntime(['a' => 1]),
        ]);
        $requiredTail = self::constantArrayFromRuntime(['b' => 2]);

        $result = (new ArrayMergeType([$optionalPrefix, $requiredTail]))->resolve();
        $description = $result->describe(VerbosityLevel::precise());

        $this->assertTrue($result->isIterableAtLeastOnce()->yes(), $description);
        $this->assertTrue($result->isSuperTypeOf($requiredTail)->yes(), $description);
        $this->assertTrue(
            $result->isSuperTypeOf(self::constantArrayFromRuntime(['a' => 1, 'b' => 2]))->yes(),
            $description,
        );
    }

    /**
     * @param array<string, Type> $types
     * @param non-empty-list<string> $alternatives
     */
    private static function unionOf(array $types, array $alternatives): Type
    {
        $selectedTypes = [];
        foreach ($alternatives as $alternative) {
            $selectedTypes[] = $types[$alternative];
        }

        return count($selectedTypes) === 1 ? $selectedTypes[0] : new UnionType($selectedTypes);
    }

    private static function optionalStringShape(): Type
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();
        $builder->setOffsetValueType(
            new ConstantStringType('a'),
            new ConstantIntegerType(1),
            true,
        );

        return $builder->getArray();
    }

    /** @param array<int|string, int> $values */
    private static function constantArrayFromRuntime(array $values): Type
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        foreach ($values as $key => $value) {
            $builder->setOffsetValueType(
                is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key),
                new ConstantIntegerType($value),
            );
        }

        return $builder->getArray();
    }
}
