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
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use function array_flip;
use function array_keys;
use function array_merge;
use function array_values;
use function implode;
use function is_int;
use function sprintf;

final class ArrayMergeKeyOrderTest extends PHPStanTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }

    /** @return iterable<string, array{string, non-empty-list<array<int|string, int|string>>}> */
    public static function orderProvider(): iterable
    {
        yield 'absent optional string prefix' => [
            'array-merge<array{a?: 1}, array{b: 2, a: 3}>',
            [[], ['b' => 2, 'a' => 3]],
        ];
        yield 'present optional string prefix' => [
            'array-merge<array{a?: 1}, array{b: 2, a: 3}>',
            [['a' => 1], ['b' => 2, 'a' => 3]],
        ];
        yield 'duplicate flipped values' => [
            "array-merge<array{a?: 'x'}, array{b: 'x', a: 'x'}>",
            [[], ['b' => 'x', 'a' => 'x']],
        ];
        yield 'only the second optional key is present' => [
            'array-merge<array{a?: 1, b?: 2}, array{a: 3, b: 4}>',
            [['b' => 2], ['a' => 3, 'b' => 4]],
        ];
        yield 'union prefix' => [
            'array-merge<array{a: 1}|array{b: 2}, array{b: 3, a: 4}>',
            [['b' => 2], ['b' => 3, 'a' => 4]],
        ];
        yield 'first order in a single union operand' => [
            'array-merge<array{a: 1, b: 2}|array{b: 2, a: 1}>',
            [['a' => 1, 'b' => 2]],
        ];
        yield 'opposite order in a single union operand' => [
            'array-merge<array{a: 1, b: 2}|array{b: 2, a: 1}>',
            [['b' => 2, 'a' => 1]],
        ];
        yield 'optional integer before a string and an appended integer' => [
            'array-merge<array{0?: 1, b: 2}, array{3}>',
            [['b' => 2], [3]],
        ];
        yield 'optional integer inside a single mixed operand' => [
            'array-merge<array{0?: 1, b: 2, 1: 3}>',
            [['b' => 2, 1 => 3]],
        ];

        $optionalFields = [];
        $tailFields = [];
        $tail = [];
        for ($i = 0; $i < 12; $i++) {
            $optionalFields[] = sprintf('k%d?: %d', $i, $i);
            if ($i < 11) {
                $tailFields[] = sprintf('k%d: %d', $i, $i);
                $tail['k' . $i] = $i;
            }
        }

        // The empty, first-only, last-only, and full optional subsets all agree.
        // A middle-only subset exposes the order change that sampling would miss.
        yield 'large optional shape must not rely on sampled subsets' => [
            sprintf(
                'array-merge<array{k11: 11}, array{%s}, array{%s}>',
                implode(', ', $optionalFields),
                implode(', ', $tailFields),
            ),
            [['k11' => 11], ['k5' => 5], $tail],
        ];
    }

    /** @param non-empty-list<array<int|string, int|string>> $operands */
    #[DataProvider('orderProvider')]
    public function testDerivedArraysContainNativeOutcomes(string $phpDoc, array $operands): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($phpDoc);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();

        $this->assertDerivedArraysContainNativeOutcome($phpDoc, $result, $operands);
    }

    /** @return iterable<string, array{string, array{list<array<array-key, int>>, list<array<array-key, int>>}}> */
    public static function completeBranchProvider(): iterable
    {
        yield 'cross-product of optional string keys' => [
            'array-merge<array{a?: 1, b?: 2}, array{b?: 3, c: 4, a?: 5}>',
            [
                [[], ['a' => 1], ['b' => 2], ['a' => 1, 'b' => 2]],
                [['c' => 4], ['b' => 3, 'c' => 4], ['c' => 4, 'a' => 5], ['b' => 3, 'c' => 4, 'a' => 5]],
            ],
        ];
        yield 'opposite union orders followed by an optional prefix' => [
            'array-merge<array{a: 1, b: 2}|array{b: 3, a: 4}, array{a?: 5, c: 6}>',
            [
                [['a' => 1, 'b' => 2], ['b' => 3, 'a' => 4]],
                [['c' => 6], ['a' => 5, 'c' => 6]],
            ],
        ];
        yield 'optional integer and string keys' => [
            'array-merge<array{0?: 1, a?: 2, 1?: 3}, array{b: 4, 0?: 5}>',
            [
                [
                    [],
                    [1],
                    ['a' => 2],
                    [1, 'a' => 2],
                    [1 => 3],
                    [0 => 1, 1 => 3],
                    ['a' => 2, 1 => 3],
                    [0 => 1, 'a' => 2, 1 => 3],
                ],
                [['b' => 4], ['b' => 4, 0 => 5]],
            ],
        ];
    }

    /** @param array{list<array<int|string, int>>, list<array<int|string, int>>} $operandBranches */
    #[DataProvider('completeBranchProvider')]
    public function testEverySmallOptionalAndUnionBranchIsSound(string $phpDoc, array $operandBranches): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($phpDoc);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();

        foreach ($operandBranches[0] as $first) {
            foreach ($operandBranches[1] as $second) {
                $this->assertDerivedArraysContainNativeOutcome($phpDoc, $result, [$first, $second]);
            }
        }
    }

    /** @param non-empty-list<array<int|string, int|string>> $operands */
    private function assertDerivedArraysContainNativeOutcome(string $phpDoc, Type $result, array $operands): void
    {
        $native = array_merge(...$operands);

        $outcomes = [
            'merge' => [$result, $native],
            'values' => [$result->getValuesArray(), array_values($native)],
            'keys' => [$result->getKeysArray(), array_keys($native)],
            'flip' => [$result->flipArray(), array_flip($native)],
        ];

        foreach ($outcomes as $operation => [$inferred, $runtime]) {
            $expected = self::runtimeArrayType($runtime);
            $this->assertTrue($inferred->isSuperTypeOf($expected)->yes(), sprintf(
                '%s of %s inferred %s, excluding native outcome %s.',
                $operation,
                $phpDoc,
                $inferred->describe(VerbosityLevel::precise()),
                $expected->describe(VerbosityLevel::precise()),
            ));
        }
    }

    public function testStableOrderRetainsShapePrecision(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);

        $scenarios = [
            'array-merge<array{a?: 1}, array{b: 2}>' => 'array{a?: 1, b: 2}',
            'array-merge<array{a: 1, b: 2}, array{b: 3, a: 4}>' => 'array{a: 4, b: 3}',
            'array-merge<array{a: 1}|array{b: 2}>' => 'array{a?: 1, b?: 2}&non-empty-array',
            'array-merge<array{0?: 1}, array{2}>' => 'array{0: 1|2, 1?: 2}',
        ];

        foreach ($scenarios as $phpDoc => $expected) {
            $merge = $resolver->resolve($phpDoc);
            $this->assertInstanceOf(ArrayMergeType::class, $merge);
            $this->assertTrue($resolver->resolve($expected)->equals($merge->resolve()), $phpDoc);
        }
    }

    public function testExactlySixtyFourOptionalVariantsRetainStableShapePrecision(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $phpDoc = 'array-merge<array{k0?: 0, k1?: 1, k2?: 2, k3?: 3, k4?: 4, k5?: 5}, array{k5: 6}>';
        $expected = 'array{k0?: 0, k1?: 1, k2?: 2, k3?: 3, k4?: 4, k5: 6}';
        $merge = $resolver->resolve($phpDoc);

        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $this->assertTrue($resolver->resolve($expected)->equals($merge->resolve()), $phpDoc);
    }

    public function testDisjointStringKeysRetainShapePrecisionBeyondOptionalVariantLimit(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $phpDoc = 'array-merge<array{a?: 1, b?: 2, c?: 3, d?: 4, e?: 5, f?: 6, g?: 7}, array{h: 8}>';
        $expected = 'array{a?: 1, b?: 2, c?: 3, d?: 4, e?: 5, f?: 6, g?: 7, h: 8}';
        $merge = $resolver->resolve($phpDoc);

        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();
        $this->assertTrue($resolver->resolve($expected)->equals($result), $result->describe(VerbosityLevel::precise()));
    }

    public function testRawNumericStringKeysAreReindexedInOrder(): void
    {
        $merge = new ArrayMergeType([
            new ConstantArrayType(
                [new ConstantStringType('7'), new ConstantStringType('b')],
                [new ConstantIntegerType(1), new ConstantIntegerType(2)],
                [0],
                [0],
            ),
            new ConstantArrayType([new ConstantStringType('8')], [new ConstantIntegerType(3)]),
        ]);

        $this->assertDerivedArraysContainNativeOutcome(
            $merge->describe(VerbosityLevel::precise()),
            $merge->resolve(),
            [['b' => 2], [8 => 3]],
        );
    }

    /** @param array<int|string, int|string> $values */
    private static function runtimeArrayType(array $values): Type
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        foreach ($values as $key => $value) {
            $builder->setOffsetValueType(
                is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key),
                is_int($value) ? new ConstantIntegerType($value) : new ConstantStringType($value),
            );
        }

        return $builder->getArray();
    }
}
