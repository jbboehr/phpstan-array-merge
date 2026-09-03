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
use function array_keys;
use function array_map;
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

    public function testMultipleTrailingShapesRetainKnownOffsetsAtBuilderBoundary(): void
    {
        $firstShapeKeyTypes = [];
        $firstShapeValueTypes = [];

        for ($i = 0; $i < 256; $i++) {
            $firstShapeKeyTypes[] = new ConstantStringType('first' . $i);
            $firstShapeValueTypes[] = new ConstantIntegerType($i);
        }

        $result = (new ArrayMergeType([
            new ArrayType(new StringType(), new IntegerType()),
            new ConstantArrayType($firstShapeKeyTypes, $firstShapeValueTypes),
            new ConstantArrayType(
                [new ConstantStringType('first0'), new ConstantStringType('last')],
                [new ConstantStringType('overwritten'), new ConstantStringType('tail')],
            ),
        ]))->resolve();

        if (!self::supportsUnsealedShapes()) {
            $this->assertSame([], $result->getConstantArrays());
            return;
        }

        $constantArrays = $result->getConstantArrays();
        $this->assertCount(
            1,
            $constantArrays,
            sprintf(
                'All eligible trailing-shape offsets must remain known when their combined size crosses 256; got %s.',
                $result->describe(VerbosityLevel::precise()),
            ),
        );
        $this->assertCount(257, $constantArrays[0]->getKeyTypes());
        $this->assertSame('first0', $constantArrays[0]->getKeyTypes()[0]->getValue());
        $this->assertSame('last', $constantArrays[0]->getKeyTypes()[256]->getValue());
        $this->assertTrue($constantArrays[0]->hasOffsetValueType(new ConstantStringType('first0'))->yes());
        $this->assertTrue((new ConstantStringType('overwritten'))->equals(
            $constantArrays[0]->getOffsetValueType(new ConstantStringType('first0')),
        ));
        $this->assertTrue($constantArrays[0]->hasOffsetValueType(new ConstantStringType('first255'))->yes());
        $this->assertTrue($constantArrays[0]->hasOffsetValueType(new ConstantStringType('last'))->yes());
    }

    public function testMultipleTrailingShapesMatchNativeOverwriteAndInsertionOrder(): void
    {
        $runtimeShapes = [
            ['first' => 'left', '08' => 'leading zero', 'shared' => 'first value', 'middle' => 1],
            ['+8' => true, 'second' => true, 'shared' => 'second value', '08' => 'overwritten leading zero'],
            ['middle' => 'last middle', '-0' => false, 'third' => 3, '+8' => false, 'shared' => false],
        ];
        $shapeTypes = array_map(self::constantArrayFromRuntime(...), $runtimeShapes);

        $result = (new ArrayMergeType([
            new ArrayType(new StringType(), new IntegerType()),
            ...$shapeTypes,
        ]))->resolve();
        $runtimeOutcome = self::constantArrayFromRuntime(array_merge(
            ['dynamic' => 7],
            ...$runtimeShapes,
        ));

        $this->assertTrue(
            $result->isSuperTypeOf($runtimeOutcome)->yes(),
            sprintf(
                'Inferred %s must contain representative native result %s.',
                $result->describe(VerbosityLevel::precise()),
                $runtimeOutcome->describe(VerbosityLevel::precise()),
            ),
        );

        if (!self::supportsUnsealedShapes()) {
            $this->assertSame([], $result->getConstantArrays());
            return;
        }

        $constantArrays = $result->getConstantArrays();
        $this->assertCount(1, $constantArrays);
        $actualKnownKeys = array_map(
            static fn(ConstantIntegerType|ConstantStringType $keyType): int|string => $keyType->getValue(),
            $constantArrays[0]->getKeyTypes(),
        );
        $nativeKnownKeys = array_keys(array_merge(...$runtimeShapes));
        $this->assertSame($nativeKnownKeys, $actualKnownKeys);

        $expectedKnownOffsets = self::constantArrayFromRuntime(array_merge(...$runtimeShapes));
        $expectedConstantArrays = $expectedKnownOffsets->getConstantArrays();
        $this->assertCount(1, $expectedConstantArrays);

        foreach ($expectedConstantArrays[0]->getKeyTypes() as $keyType) {
            $this->assertTrue(
                $expectedConstantArrays[0]->getOffsetValueType($keyType)->equals(
                    $constantArrays[0]->getOffsetValueType($keyType),
                ),
                sprintf('Known offset %s must have the native last-writer value type.', $keyType->getValue()),
            );
        }

        $this->assertTrue((new IntegerType())->equals(
            $constantArrays[0]->getOffsetValueType(new ConstantStringType('dynamic')),
        ));
    }

    public function testDisqualifiedThirdOperandForcesConservativeFallback(): void
    {
        $generic = new ArrayType(new StringType(), new IntegerType());
        $validShape = new ConstantArrayType(
            [new ConstantStringType('fixed')],
            [new StringType()],
        );
        $optionalShape = new ConstantArrayType(
            [new ConstantStringType('fixed')],
            [new BooleanType()],
            [0],
            [0],
        );
        $integerShape = new ConstantArrayType(
            [new ConstantIntegerType(7)],
            [new BooleanType()],
            [8],
        );
        $canonicalIntegerStringShape = new ConstantArrayType(
            [new ConstantStringType('7')],
            [new BooleanType()],
        );
        $otherShape = new ConstantArrayType(
            [new ConstantStringType('other')],
            [new BooleanType()],
        );
        $unusualAutoIndexShape = new ConstantArrayType(
            [new ConstantStringType('other')],
            [new BooleanType()],
            [7],
        );

        /**
         * @var array<string, array{
         *     non-empty-list<Type>,
         *     non-empty-list<array<int|string, bool|int|string>>
         * }> $scenarios
         */
        $scenarios = [
            'optional keys' => [
                [$generic, $validShape, $optionalShape],
                [['dynamic' => 1], ['fixed' => 'shape'], []],
            ],
            'integer keys' => [
                [$generic, $validShape, $integerShape],
                [['dynamic' => 1], ['fixed' => 'shape'], [7 => true]],
            ],
            'canonical integer string keys' => [
                [$generic, $validShape, $canonicalIntegerStringShape],
                [['dynamic' => 1], ['fixed' => 'shape'], [7 => true]],
            ],
            'shape unions' => [
                [$generic, $validShape, new UnionType([$validShape, $otherShape])],
                [['dynamic' => 1], ['fixed' => 'shape'], ['other' => true]],
            ],
            'shape intersections' => [
                [$generic, $validShape, new IntersectionType([$otherShape, new NonEmptyArrayType()])],
                [['dynamic' => 1], ['fixed' => 'shape'], ['other' => true]],
            ],
            'generic operands after shapes' => [
                [$generic, $validShape, new ArrayType(new StringType(), new BooleanType())],
                [['dynamic' => 1], ['fixed' => 'shape'], ['fixed' => true]],
            ],
            'empty shapes' => [
                [$generic, $validShape, ConstantArrayTypeBuilder::createEmpty()->getArray()],
                [['dynamic' => 1], ['fixed' => 'shape'], []],
            ],
            'unusual auto-index metadata' => [
                [$generic, $validShape, $unusualAutoIndexShape],
                [['dynamic' => 1], ['fixed' => 'shape'], ['other' => true]],
            ],
        ];

        if (self::supportsUnsealedShapes()) {
            $unsealedShape = new ConstantArrayType(
                [new ConstantStringType('other')],
                [new BooleanType()],
                [0],
                [],
                TrinaryLogic::createNo(),
                [new StringType(), new BooleanType()],
            );
            $scenarios['unsealed shapes'] = [
                [$generic, $validShape, $unsealedShape],
                [['dynamic' => 1], ['fixed' => 'shape'], ['other' => true, 'extra' => false]],
            ];
        }

        foreach ($scenarios as $name => [$declaredTypes, $runtimeOperands]) {
            $result = (new ArrayMergeType($declaredTypes))->resolve();
            $this->assertSame(
                [],
                $result->getConstantArrays(),
                sprintf('Scenario %s must retain the prior conservative fallback.', $name),
            );

            $runtimeOutcome = self::constantArrayFromRuntime(array_merge(...$runtimeOperands));
            $this->assertTrue(
                $result->isSuperTypeOf($runtimeOutcome)->yes(),
                sprintf(
                    'Scenario %s inferred %s, which excludes native result %s.',
                    $name,
                    $result->describe(VerbosityLevel::precise()),
                    $runtimeOutcome->describe(VerbosityLevel::precise()),
                ),
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
