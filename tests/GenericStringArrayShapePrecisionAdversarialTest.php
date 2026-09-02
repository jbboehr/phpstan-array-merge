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
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use function array_merge;
use function is_bool;
use function is_callable;
use function is_int;
use function is_string;
use function sprintf;

final class GenericStringArrayShapePrecisionAdversarialTest extends PHPStanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer();
    }

    public function testRetainedShapeUsesLastWinsAndAcceptsNonCanonicalIntegerStringKeys(): void
    {
        $fixedKey = new ConstantStringType('fixed');
        $nonCanonicalIntegerStringKey = new ConstantStringType('08');
        $shape = new ConstantArrayType(
            [$fixedKey, $nonCanonicalIntegerStringKey],
            [new StringType(), new BooleanType()],
        );

        $result = (new ArrayMergeType([
            new ArrayType(new StringType(), new IntegerType()),
            $shape,
        ]))->resolve();

        if (!self::supportsUnsealedShapes()) {
            $this->assertSame([], $result->getConstantArrays());
            return;
        }

        $constantArrays = $result->getConstantArrays();
        $this->assertCount(1, $constantArrays);
        $this->assertTrue($result->equals($constantArrays[0]));
        $this->assertTrue($constantArrays[0]->hasOffsetValueType($fixedKey)->yes());
        $this->assertTrue((new StringType())->equals($constantArrays[0]->getOffsetValueType($fixedKey)));
        $this->assertTrue($constantArrays[0]->hasOffsetValueType($nonCanonicalIntegerStringKey)->yes());
        $this->assertTrue((new BooleanType())->equals(
            $constantArrays[0]->getOffsetValueType($nonCanonicalIntegerStringKey),
        ));
        $this->assertTrue((new IntegerType())->equals(
            $constantArrays[0]->getOffsetValueType(new ConstantStringType('dynamic')),
        ));

        $runtimeOutcome = self::constantArrayFromRuntime([
            'fixed' => 'winner',
            'dynamic' => 7,
            '08' => true,
        ]);
        $this->assertTrue(
            $result->isSuperTypeOf($runtimeOutcome)->yes(),
            sprintf(
                'Inferred %s must contain representative last-wins result %s.',
                $result->describe(VerbosityLevel::precise()),
                $runtimeOutcome->describe(VerbosityLevel::precise()),
            ),
        );
    }

    public function testBoundaryCasesContainRepresentativeNativeMergeOutcomes(): void
    {
        $fixedStringShape = new ConstantArrayType(
            [new ConstantStringType('fixed')],
            [new StringType()],
        );
        $optionalFixedStringShape = new ConstantArrayType(
            [new ConstantStringType('fixed')],
            [new StringType()],
            [0],
            [0],
        );
        $integerShape = new ConstantArrayType(
            [new ConstantIntegerType(7)],
            [new BooleanType()],
            [8],
        );
        $otherBooleanShape = new ConstantArrayType(
            [new ConstantStringType('other')],
            [new BooleanType()],
        );

        /**
         * @var array<string, array{
         *     non-empty-list<Type>,
         *     non-empty-list<array<int|string, bool|int|string>>
         * }> $scenarios
         */
        $scenarios = [
            'reverse operand order can overwrite the fixed key' => [
                [$fixedStringShape, new ArrayType(new StringType(), new IntegerType())],
                [['fixed' => 'shape'], ['fixed' => 11]],
            ],
            'an optional trailing shape can be absent' => [
                [new ArrayType(new StringType(), new IntegerType()), $optionalFixedStringShape],
                [[], []],
            ],
            'integer shape keys are reindexed' => [
                [new ArrayType(new StringType(), new IntegerType()), $integerShape],
                [['dynamic' => 3], [7 => true]],
            ],
            'a third generic operand can overwrite the fixed key' => [
                [
                    new ArrayType(new StringType(), new IntegerType()),
                    $fixedStringShape,
                    new ArrayType(new StringType(), new BooleanType()),
                ],
                [['dynamic' => 3], ['fixed' => 'shape'], ['fixed' => true]],
            ],
            'a generic union retains every value alternative' => [
                [
                    new UnionType([
                        new ArrayType(new StringType(), new IntegerType()),
                        new ArrayType(new StringType(), new BooleanType()),
                    ]),
                    $fixedStringShape,
                ],
                [['dynamic' => true], ['fixed' => 'shape']],
            ],
            'a trailing shape union retains every alternative' => [
                [
                    new ArrayType(new StringType(), new IntegerType()),
                    new UnionType([$fixedStringShape, $otherBooleanShape]),
                ],
                [['dynamic' => 3], ['other' => true]],
            ],
            'a generic intersection remains sound' => [
                [
                    new IntersectionType([
                        new ArrayType(new StringType(), new IntegerType()),
                        new NonEmptyArrayType(),
                    ]),
                    $fixedStringShape,
                ],
                [['fixed' => 5], ['fixed' => 'shape']],
            ],
        ];

        if (self::supportsUnsealedShapes()) {
            $unsealedShape = new ConstantArrayType(
                [new ConstantStringType('fixed')],
                [new StringType()],
                [0],
                [],
                TrinaryLogic::createNo(),
                [new StringType(), new BooleanType()],
            );
            $scenarios['an existing unsealed tail is not discarded'] = [
                [new ArrayType(new StringType(), new IntegerType()), $unsealedShape],
                [['fromGeneric' => 3], ['fixed' => 'shape', 'fromShapeTail' => true]],
            ];
        }

        foreach ($scenarios as $name => [$declaredTypes, $runtimeOperands]) {
            foreach ($runtimeOperands as $i => $runtimeOperand) {
                $runtimeOperandType = self::constantArrayFromRuntime($runtimeOperand);
                $this->assertTrue(
                    $declaredTypes[$i]->isSuperTypeOf($runtimeOperandType)->yes(),
                    sprintf(
                        'Invalid scenario %s: declared operand %s does not contain %s.',
                        $name,
                        $declaredTypes[$i]->describe(VerbosityLevel::precise()),
                        $runtimeOperandType->describe(VerbosityLevel::precise()),
                    ),
                );
            }

            $result = (new ArrayMergeType($declaredTypes))->resolve();
            $runtimeOutcome = self::constantArrayFromRuntime(array_merge(...$runtimeOperands));

            $this->assertTrue(
                $result->isSuperTypeOf($runtimeOutcome)->yes(),
                sprintf(
                    'Scenario %s inferred %s, which excludes native array_merge result %s.',
                    $name,
                    $result->describe(VerbosityLevel::precise()),
                    $runtimeOutcome->describe(VerbosityLevel::precise()),
                ),
            );
        }
    }

    public function testUnusualAutoIndexStateIsNotRetainedInAConflictingShape(): void
    {
        $unusualShape = new ConstantArrayType(
            [new ConstantStringType('fixed')],
            [new StringType()],
            [7],
        );

        $result = (new ArrayMergeType([
            new ArrayType(new StringType(), new IntegerType()),
            $unusualShape,
        ]))->resolve();

        $runtimeOutcome = self::constantArrayFromRuntime(['fixed' => 'shape']);
        $this->assertTrue(
            $result->isSuperTypeOf($runtimeOutcome)->yes(),
            'The unusual construction metadata must not exclude the visible array_merge result.',
        );

        $constantArrays = $result->getConstantArrays();

        if ([] === $constantArrays) {
            return;
        }

        foreach ($constantArrays as $constantArray) {
            $this->assertSame(
                [0],
                $constantArray->getNextAutoIndexes(),
                'array_merge creates a new array whose first available integer offset is zero.',
            );
        }
    }

    private static function supportsUnsealedShapes(): bool
    {
        return self::hasOptionalMethod(ConstantArrayTypeBuilder::createEmpty(), 'makeUnsealed');
    }

    private static function hasOptionalMethod(object $object, string $method): bool
    {
        return is_callable([$object, $method]);
    }

    /**
     * @param array<int|string, bool|int|string> $values
     */
    private static function constantArrayFromRuntime(array $values): Type
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        foreach ($values as $key => $value) {
            $builder->setOffsetValueType(
                is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key),
                match (true) {
                    is_bool($value) => new ConstantBooleanType($value),
                    is_int($value) => new ConstantIntegerType($value),
                    is_string($value) => new ConstantStringType($value),
                },
            );
        }

        return $builder->getArray();
    }
}
