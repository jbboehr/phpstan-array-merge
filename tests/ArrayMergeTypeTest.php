<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXV John Boehr & contributors
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
use jbboehr\PHPStan\ArrayMerge\ArrayMergeTypeOperandBenevolentUnionType;
use jbboehr\PHPStan\ArrayMerge\ArrayMergeTypeOperandUnionType;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryDecimalIntegerStringType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use function array_map;
use function array_merge;
use function hrtime;
use function is_callable;
use function is_int;
use function spl_object_id;

final class ArrayMergeTypeTest extends PHPStanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer();
    }

    public function testGetTypes(): void
    {
        $types = [new MixedType()];

        $this->assertSame($types, (new ArrayMergeType($types))->getTypes());
    }

    public function testGetReferencedClasses(): void
    {
        $type = new ArrayMergeType([
            new ArrayType(new MixedType(), new ObjectType('stdClass')),
            new ArrayType(new MixedType(), new ObjectType('Throwable')),
        ]);

        $this->assertSame(['stdClass', 'Throwable'], $type->getReferencedClasses());
    }

    public function testGetReferencedTemplateTypes(): void
    {
        $variance = TemplateTypeVariance::createInvariant();
        $firstReference = new \stdClass();
        $secondReference = new \stdClass();
        $firstType = $this->createMock(Type::class);
        $secondType = $this->createMock(Type::class);
        $firstType->expects(self::once())
            ->method('getReferencedTemplateTypes')
            ->with($variance)
            ->willReturn([$firstReference]);
        $secondType->expects(self::once())
            ->method('getReferencedTemplateTypes')
            ->with($variance)
            ->willReturn([$secondReference]);
        $type = new ArrayMergeType([$firstType, $secondType]);

        $references = $type->getReferencedTemplateTypes($variance);

        $this->assertSame(
            [spl_object_id($firstReference), spl_object_id($secondReference)],
            array_map(static fn(object $reference): int => spl_object_id($reference), $references),
        );
    }

    public function testEquals(): void
    {
        $left = new ArrayMergeType([new MixedType()]);
        $right = new ArrayMergeType([new IntegerType()]);

        $multiLeft = new ArrayMergeType([new IntegerType(), new StringType()]);
        $multiRight = new ArrayMergeType([new IntegerType(), new StringType()]);
        $sharedPrefix = new ArrayMergeType([new MixedType(), new StringType()]);

        $this->assertFalse($left->equals($right));
        $this->assertFalse($right->equals($left));
        $this->assertTrue($multiLeft->equals($multiRight));
        $this->assertTrue($multiRight->equals($multiLeft));
        $this->assertFalse($left->equals($multiLeft));
        $this->assertFalse($multiLeft->equals($left));
        $this->assertFalse($left->equals($sharedPrefix));
        $this->assertFalse($left->equals(new IntegerType()));
    }

    public function testIsResolvable(): void
    {
        $template = $this->createMock(TemplateType::class);
        $template->method('isArray')->willReturn(TrinaryLogic::createYes());

        $this->assertTrue((new ArrayMergeType([new ArrayType(new IntegerType(), new StringType())]))->isResolvable());
        $this->assertFalse((new ArrayMergeType([$template]))->isResolvable());
    }

    public function testDescribe(): void
    {
        $type = new ArrayMergeType([
            new ArrayType(new MixedType(), new ObjectType('stdClass')),
            new ArrayType(new MixedType(), new ObjectType('Throwable')),
        ]);

        $this->assertSame('array-merge<array<stdClass>, array<Throwable>>', $type->describe(VerbosityLevel::precise()));
        $this->assertSame('array<stdClass|Throwable>', $type->resolve()->describe(VerbosityLevel::precise()));
    }

    public function testResolveRejectsOperandsThatAreNotDefinitelyArrays(): void
    {
        $this->assertInstanceOf(
            ErrorType::class,
            (new ArrayMergeType([new MixedType(true)]))->resolve(),
        );
        $this->assertInstanceOf(
            ErrorType::class,
            (new ArrayMergeType([
                new UnionType([
                    new ArrayType(new IntegerType(), new StringType()),
                    new StringType(),
                ]),
            ]))->resolve(),
        );
    }

    public function testResolveRejectsBenevolentUnionWithNonArrayAlternative(): void
    {
        $operand = new BenevolentUnionType([
            new ArrayType(new IntegerType(), new StringType()),
            new StringType(),
        ]);

        $this->assertTrue($operand->isArray()->maybe());
        $this->assertInstanceOf(ErrorType::class, (new ArrayMergeType([$operand]))->resolve());
    }

    public function testResolvePreservesBenevolentUnionWhenRemovingNeverAlternative(): void
    {
        $possiblyEmpty = new ArrayType(new StringType(), new IntegerType());
        $nonEmpty = new IntersectionType([$possiblyEmpty, new NonEmptyArrayType()]);
        $withoutNever = (new ArrayMergeType([
            new BenevolentUnionType([$possiblyEmpty, $nonEmpty]),
        ]))->resolve();
        $withNever = (new ArrayMergeType([
            new BenevolentUnionType([$possiblyEmpty, $nonEmpty, new NeverType()]),
        ]))->resolve();

        $this->assertTrue($withoutNever->isIterableAtLeastOnce()->yes());
        $this->assertTrue($withoutNever->equals($withNever));
    }

    public function testResolveUsesOverriddenResult(): void
    {
        $type = new class ([new MixedType()]) extends ArrayMergeType {
            protected function getResult(): Type
            {
                return new StringType();
            }
        };

        $this->assertInstanceOf(StringType::class, $type->resolve());
    }

    public function testResolvePreservesLargeTopLevelConstantShape(): void
    {
        $keyTypes = [];
        $valueTypes = [];

        for ($i = 0; $i <= 256; $i++) {
            $keyTypes[] = new ConstantStringType('key' . $i);
            $valueTypes[] = new ConstantIntegerType($i);
        }

        $operand = new ConstantArrayType($keyTypes, $valueTypes, [0], [0, 128, 256]);
        $this->assertCount(257, $operand->getKeyTypes());

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertInstanceOf(ConstantArrayType::class, $result);
        $this->assertTrue($operand->equals($result));
        $this->assertTrue($result->hasOffsetValueType(new ConstantStringType('key0'))->maybe());
        $this->assertTrue($result->hasOffsetValueType(new ConstantStringType('key1'))->yes());
        $this->assertTrue($result->hasOffsetValueType(new ConstantStringType('key256'))->maybe());
        $this->assertTrue(
            (new ConstantIntegerType(128))->equals(
                $result->getOffsetValueType(new ConstantStringType('key128')),
            ),
        );
    }

    public function testResolvePreservesTopLevelClosureShape(): void
    {
        $closureType = self::getContainer()->getByType(TypeStringResolver::class)->resolve('Closure(): int');
        $this->assertInstanceOf(ClosureType::class, $closureType);
        $keyTypes = [];
        $valueTypes = [];

        for ($i = 0; $i < 32; $i++) {
            $keyTypes[] = new ConstantStringType('callback' . $i);
            $valueTypes[] = $closureType;
        }

        $operand = new ConstantArrayType($keyTypes, $valueTypes, [0], [31]);
        $this->assertCount(32, $operand->getKeyTypes());

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertInstanceOf(ConstantArrayType::class, $result);
        $this->assertTrue($operand->equals($result));
        $this->assertTrue($result->hasOffsetValueType(new ConstantStringType('callback0'))->yes());
        $this->assertTrue($result->hasOffsetValueType(new ConstantStringType('callback31'))->maybe());
        $this->assertTrue($closureType->equals(
            $result->getOffsetValueType(new ConstantStringType('callback31')),
        ));
    }

    public function testResolveReindexesCanonicalIntegerStringKey(): void
    {
        $operand = new ConstantArrayType(
            [new ConstantStringType('7')],
            [new ConstantStringType('value')],
        );

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertInstanceOf(ConstantArrayType::class, $result);
        $this->assertCount(1, $result->getKeyTypes());
        $this->assertTrue((new ConstantIntegerType(0))->equals($result->getKeyTypes()[0]));
    }

    public function testResolveFallsBackWhenLargeListNormalizationDegrades(): void
    {
        $operandKeyTypes = [];
        $valueTypes = [];

        for ($i = 0; $i <= 256; $i++) {
            $operandKeyTypes[] = new ConstantIntegerType(1000 + $i);
            $valueTypes[] = new ConstantStringType('value' . $i);
        }

        $operand = new ConstantArrayType($operandKeyTypes, $valueTypes, [1257]);

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue($result->isIterableAtLeastOnce()->yes());
        $this->assertTrue($result->isList()->yes());
        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantIntegerType(256))->yes(),
        );
        $this->assertTrue(
            $result->getIterableValueType()->isSuperTypeOf(new ConstantStringType('value0'))->yes(),
        );
        $this->assertTrue(
            $result->getIterableValueType()->isSuperTypeOf(new ConstantStringType('value256'))->yes(),
        );
    }

    public function testDegradedConstantUnionReindexesCanonicalIntegerStringKey(): void
    {
        $leftKeyTypes = [new ConstantStringType('7')];
        $leftValueTypes = [new ConstantStringType('numeric')];
        $rightKeyTypes = [];
        $rightValueTypes = [];

        for ($i = 0; $i < 31; $i++) {
            $leftKeyTypes[] = new ConstantStringType('left' . $i);
            $leftValueTypes[] = new ConstantStringType('left-value' . $i);
        }

        for ($i = 0; $i < 32; $i++) {
            $rightKeyTypes[] = new ConstantStringType('right' . $i);
            $rightValueTypes[] = new ConstantStringType('right-value' . $i);
        }

        $operand = new UnionType([
            new ConstantArrayType($leftKeyTypes, $leftValueTypes),
            new ConstantArrayType($rightKeyTypes, $rightValueTypes),
        ]);

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantIntegerType(0))->yes(),
            'array_merge() reindexes the canonical integer-string key to integer key 0.',
        );
    }

    public function testConstantUnionReindexesCanonicalIntegerStringKeysByPosition(): void
    {
        $operand = new UnionType([
            new ConstantArrayType(
                [new ConstantStringType('7')],
                [new ConstantStringType('short')],
            ),
            new ConstantArrayType(
                [new ConstantIntegerType(-1), new ConstantStringType('7')],
                [new ConstantStringType('first'), new ConstantStringType('second')],
            ),
        ]);

        $result = (new ArrayMergeType([$operand]))->resolve();
        $shortResult = new ConstantArrayType(
            [new ConstantIntegerType(0)],
            [new ConstantStringType('short')],
            [1],
        );
        $longResult = new ConstantArrayType(
            [new ConstantIntegerType(0), new ConstantIntegerType(1)],
            [new ConstantStringType('first'), new ConstantStringType('second')],
            [2],
        );

        $this->assertTrue($result->isSuperTypeOf($shortResult)->yes());
        $this->assertTrue($result->isSuperTypeOf($longResult)->yes());
    }

    public function testConstantUnionsRemainSoundAcrossAllNativeArrayMergeBranches(): void
    {
        $leftAlternatives = [
            [
                new ConstantArrayType(
                    [new ConstantStringType('7'), new ConstantStringType('keep')],
                    [new ConstantStringType('left-number'), new ConstantStringType('left-keep')],
                ),
                [7 => 'left-number', 'keep' => 'left-keep'],
            ],
            [
                new ConstantArrayType(
                    [
                        new ConstantStringType('08'),
                        new ConstantIntegerType(-2),
                        new ConstantStringType('9'),
                    ],
                    [
                        new ConstantStringType('leading-zero'),
                        new ConstantStringType('left-negative'),
                        new ConstantStringType('left-second-number'),
                    ],
                ),
                ['08' => 'leading-zero', -2 => 'left-negative', 9 => 'left-second-number'],
            ],
        ];
        $rightAlternatives = [
            [
                new ConstantArrayType(
                    [new ConstantStringType('keep'), new ConstantStringType('10')],
                    [new ConstantStringType('right-keep'), new ConstantStringType('right-number')],
                ),
                ['keep' => 'right-keep', 10 => 'right-number'],
            ],
            [
                new ConstantArrayType(
                    [new ConstantStringType('11')],
                    [new ConstantStringType('right-only-number')],
                ),
                [11 => 'right-only-number'],
            ],
        ];

        $result = (new ArrayMergeType([
            new UnionType(array_map(static fn(array $alternative): Type => $alternative[0], $leftAlternatives)),
            new UnionType(array_map(static fn(array $alternative): Type => $alternative[0], $rightAlternatives)),
        ]))->resolve();

        foreach ($leftAlternatives as $leftIndex => [, $leftRuntime]) {
            foreach ($rightAlternatives as $rightIndex => [, $rightRuntime]) {
                $builder = ConstantArrayTypeBuilder::createEmpty();

                foreach (array_merge($leftRuntime, $rightRuntime) as $key => $value) {
                    $builder->setOffsetValueType(
                        is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key),
                        new ConstantStringType($value),
                    );
                }

                $runtimeBranchType = $builder->getArray();
                $this->assertTrue(
                    $result->isSuperTypeOf($runtimeBranchType)->yes(),
                    sprintf(
                        'Inferred %s must contain native branch %d/%d: %s.',
                        $result->describe(VerbosityLevel::precise()),
                        $leftIndex,
                        $rightIndex,
                        $runtimeBranchType->describe(VerbosityLevel::precise()),
                    ),
                );
            }
        }
    }

    public function testGenericUnionReindexesCanonicalIntegerStringKey(): void
    {
        $operand = new UnionType([
            new ConstantArrayType(
                [new ConstantStringType('7')],
                [new ConstantStringType('numeric')],
            ),
            new ArrayType(new StringType(), new IntegerType()),
        ]);

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantIntegerType(0))->yes(),
        );
        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantStringType('key'))->yes(),
        );
    }

    public function testDegradedUnsealedArrayReindexesExplicitCanonicalIntegerStringKey(): void
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($builder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($builder, 'makeUnsealed'));
            return;
        }

        $keyTypes = [new ConstantStringType('7')];
        $valueTypes = [new ConstantStringType('numeric')];

        for ($i = 0; $i < 256; $i++) {
            $keyTypes[] = new ConstantStringType('key' . $i);
            $valueTypes[] = new ConstantIntegerType($i);
        }

        $operand = new ConstantArrayType(
            $keyTypes,
            $valueTypes,
            [0],
            [],
            TrinaryLogic::createMaybe(),
            [new StringType(), new StringType()],
        );

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantIntegerType(0))->yes(),
        );
        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantStringType('extra'))->yes(),
        );
    }

    public function testDegradedUnsealedArrayReindexesCanonicalIntegerStringTail(): void
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($builder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($builder, 'makeUnsealed'));
            return;
        }

        $keyTypes = [];
        $valueTypes = [];

        for ($i = 0; $i <= 256; $i++) {
            $keyTypes[] = new ConstantStringType('key' . $i);
            $valueTypes[] = new ConstantIntegerType($i);
        }

        $operand = new ConstantArrayType(
            $keyTypes,
            $valueTypes,
            [0],
            [],
            TrinaryLogic::createMaybe(),
            [new ConstantStringType('7'), new StringType()],
        );

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantIntegerType(0))->yes(),
        );
    }

    public function testDegradedUnsealedIntegerArrayRemainsList(): void
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($builder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($builder, 'makeUnsealed'));
            return;
        }

        $keyTypes = [new ConstantStringType('7')];
        $valueTypes = [new ConstantStringType('numeric')];

        for ($i = 0; $i < 256; $i++) {
            $keyTypes[] = new ConstantIntegerType(1000 + $i);
            $valueTypes[] = new ConstantIntegerType($i);
        }

        $operand = new ConstantArrayType(
            $keyTypes,
            $valueTypes,
            [1256],
            [],
            TrinaryLogic::createMaybe(),
            [new IntegerType(), new IntegerType()],
        );

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue($result->isList()->yes());
    }

    public function testUnsealedDecimalIntegerStringKeysAreReindexedAsAList(): void
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($builder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($builder, 'makeUnsealed'));
            return;
        }

        if (!class_exists(AccessoryDecimalIntegerStringType::class)) {
            $this->assertFalse(class_exists(AccessoryDecimalIntegerStringType::class));
            return;
        }

        $builder->setOffsetValueType(new ConstantIntegerType(1000), new StringType());
        $builder->makeUnsealed(
            new IntersectionType([
                new StringType(),
                new AccessoryDecimalIntegerStringType(),
            ]),
            new BooleanType(),
        );

        $result = (new ArrayMergeType([$builder->getArray()]))->resolve();

        $this->assertTrue($result->isList()->yes());
        $this->assertTrue($result->isIterableAtLeastOnce()->yes());
        $this->assertTrue($result->getIterableValueType()->isSuperTypeOf(new StringType())->yes());
        $this->assertTrue($result->getIterableValueType()->isSuperTypeOf(new BooleanType())->yes());
    }

    public function testSmallUnsealedArrayPreservesExtraOffsets(): void
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($builder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($builder, 'makeUnsealed'));
            return;
        }

        $operand = new ConstantArrayType(
            [new ConstantStringType('kept')],
            [new IntegerType()],
            [0],
            [],
            TrinaryLogic::createNo(),
            [new StringType(), new BooleanType()],
        );

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue($result->getIterableKeyType()->isSuperTypeOf(new StringType())->yes());
        $this->assertTrue($result->getIterableValueType()->isSuperTypeOf(new BooleanType())->yes());
        $this->assertTrue($result->isIterableAtLeastOnce()->yes());
    }

    public function testResolveFallsBackWhenLargeOperandNormalizationDegrades(): void
    {
        $keyTypes = [];
        $valueTypes = [];

        for ($i = 0; $i <= 256; $i++) {
            $keyTypes[] = new ConstantStringType('key' . $i);
            $valueTypes[] = new ConstantIntegerType($i);
        }

        $firstOperand = new ConstantArrayType($keyTypes, $valueTypes);
        $secondOperand = new ConstantArrayType(
            [new ConstantStringType('extra')],
            [new ConstantIntegerType(999)],
        );
        $runtimeResult = new ConstantArrayType(
            [...$keyTypes, new ConstantStringType('extra')],
            [...$valueTypes, new ConstantIntegerType(999)],
        );

        $result = (new ArrayMergeType([$firstOperand, $secondOperand]))->resolve();

        $this->assertTrue($result->isSuperTypeOf($runtimeResult)->yes());
        $this->assertTrue(
            $result->getIterableKeyType()->isSuperTypeOf(new ConstantStringType('key0'))->yes(),
        );
        $this->assertTrue(
            $result->getIterableValueType()->isSuperTypeOf(new ConstantIntegerType(0))->yes(),
        );
        $this->assertTrue($result->isIterableAtLeastOnce()->yes());
    }

    public function testResolveFallsBackWhenConstantUnionGeneralizes(): void
    {
        $leftKeyTypes = [];
        $rightKeyTypes = [];
        $leftValueTypes = [];
        $rightValueTypes = [];

        for ($i = 0; $i < 32; $i++) {
            $leftKeyTypes[] = new ConstantStringType('left' . $i);
            $rightKeyTypes[] = new ConstantStringType('right' . $i);
            $leftValueTypes[] = new ConstantIntegerType($i);
            $rightValueTypes[] = new ConstantIntegerType(100 + $i);
        }

        $leftRuntimeResult = new ConstantArrayType($leftKeyTypes, $leftValueTypes);
        $rightRuntimeResult = new ConstantArrayType($rightKeyTypes, $rightValueTypes);
        $operand = new UnionType([$leftRuntimeResult, $rightRuntimeResult]);

        $result = (new ArrayMergeType([$operand]))->resolve();

        $this->assertTrue($result->isSuperTypeOf($leftRuntimeResult)->yes());
        $this->assertTrue($result->isSuperTypeOf($rightRuntimeResult)->yes());
        $this->assertTrue($result->isIterableAtLeastOnce()->yes());
    }

    public function testOptionalNeverPruningPreservesNondefaultNextAutoIndex(): void
    {
        $historyBuilder = ConstantArrayTypeBuilder::createEmpty();
        $historyBuilder->setOffsetValueType(new ConstantIntegerType(5), new StringType());
        $innerType = $historyBuilder->getArray()->unsetOffset(new ConstantIntegerType(5));
        $this->assertInstanceOf(ConstantArrayType::class, $innerType);

        $innerBuilder = ConstantArrayTypeBuilder::createFromConstantArray($innerType);
        $innerBuilder->setOffsetValueType(new ConstantStringType('kept'), new IntegerType());
        $innerBuilder->setOffsetValueType(new ConstantStringType('ghost'), new NeverType(), true);
        $expectedInnerType = $innerBuilder->getArray();

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $expectedInnerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertTrue($expectedInnerType->equals($resultInnerType));
        $this->assertSame([6], $resultInnerType->getNextAutoIndexes());
    }

    public function testOptionalNeverPruningRemovesCanonicalIntegerStringKey(): void
    {
        $innerType = new ConstantArrayType(
            [new ConstantStringType('7')],
            [new NeverType()],
            [0],
            [0],
        );
        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertSame([], $resultInnerType->getKeyTypes());
        $this->assertTrue($resultInnerType->isList()->yes());
    }

    public function testOptionalNeverPruningPreservesSurvivingOptionalKey(): void
    {
        $keyTypes = [
            new ConstantStringType('kept'),
            new ConstantStringType('maybe'),
            new ConstantStringType('ghost'),
        ];
        $valueTypes = [new IntegerType(), new StringType(), new NeverType()];
        $innerType = new ConstantArrayType($keyTypes, $valueTypes, [0], [1, 2]);
        $expectedInnerType = new ConstantArrayType(
            [$keyTypes[0], $keyTypes[1]],
            [$valueTypes[0], $valueTypes[1]],
            [0],
            [1],
        );
        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertTrue($expectedInnerType->equals($resultInnerType));
    }

    public function testOptionalNeverPruningScalesLinearly(): void
    {
        $measure = static function (int $size): float {
            $keyTypes = [];
            $valueTypes = [];
            $optionalKeys = [];

            for ($i = 0; $i < $size; $i++) {
                $keyTypes[] = new ConstantStringType('ghost' . $i);
                $valueTypes[] = new NeverType();
                $optionalKeys[] = $i;
            }

            $innerType = new ConstantArrayType($keyTypes, $valueTypes, [0], $optionalKeys);
            $outerType = new ConstantArrayType(
                [new ConstantStringType('outer')],
                [$innerType],
            );

            $start = hrtime(true);
            $result = (new ArrayMergeType([$outerType]))->resolve();
            $elapsed = hrtime(true) - $start;

            $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));
            self::assertInstanceOf(ConstantArrayType::class, $resultInnerType);
            self::assertSame([], $resultInnerType->getKeyTypes());

            return $elapsed / 1_000_000_000;
        };

        $measure(1_000);
        $smallestDuration = min($measure(12_000), $measure(12_000), $measure(12_000));
        $largestDuration = min($measure(36_000), $measure(36_000), $measure(36_000));

        $this->assertLessThan(
            $smallestDuration * 5.5,
            $largestDuration,
            'Tripling the number of offsets should not approach quadratic (9x) growth.',
        );
    }

    public function testUninhabitedArrayNormalizationVisitsSharedTemplateGraphsOnce(): void
    {
        $leaf = $this->createMock(Type::class);
        $leaf->expects(self::once())->method('traverse')->willReturnSelf();
        $leaf->method('isArray')->willReturn(TrinaryLogic::createNo());
        $builder = ConstantArrayTypeBuilder::createEmpty();
        $builder->setOffsetValueType(new ConstantStringType('inner'), $leaf);
        $bound = $builder->getArray();
        $builder = ConstantArrayTypeBuilder::createEmpty();
        $builder->setOffsetValueType(new ConstantStringType('default'), $leaf);
        $default = $builder->getArray();
        $template = null;
        $createTemplate = new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeFactory',
            'create',
        );
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, __FUNCTION__);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);

        for ($i = 0; $i < 8; $i++) {
            $template = $createTemplate->getNumberOfParameters() >= 6
                ? $createTemplate->invoke(
                    null,
                    $scope,
                    'T' . $i,
                    $bound,
                    TemplateTypeVariance::createInvariant(),
                    null,
                    $default,
                )
                : $createTemplate->invoke(
                    null,
                    $scope,
                    'T' . $i,
                    $bound,
                    TemplateTypeVariance::createInvariant(),
                );
            $this->assertInstanceOf(TemplateType::class, $template);
            $builder = ConstantArrayTypeBuilder::createEmpty();
            $builder->setOffsetValueType(new ConstantStringType('inner'), $template);
            $bound = $builder->getArray();
            $builder = ConstantArrayTypeBuilder::createEmpty();
            $builder->setOffsetValueType(new ConstantStringType('default'), $template);
            $default = $builder->getArray();
        }

        $this->assertInstanceOf(TemplateType::class, $template);
        $this->assertSame($template, ArrayMergeType::normalizeUninhabitedArrays($template));
    }

    public function testUninhabitedArrayNormalizationPreservesViableTemplateIdentity(): void
    {
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, __FUNCTION__);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);
        $impossibleArray = new IntersectionType([
            new ArrayType(new NeverType(), new StringType()),
            new NonEmptyArrayType(),
        ]);
        $template = (new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeFactory',
            'create',
        ))->invoke(
            null,
            $scope,
            'T',
            new UnionType([$impossibleArray, new IntegerType()]),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(TemplateType::class, $template);

        $this->assertSame($template, ArrayMergeType::normalizeUninhabitedArrays($template));
    }

    public function testMergeInferenceUsesViableTemplateBoundBranches(): void
    {
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, __FUNCTION__);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);
        $impossibleBuilder = ConstantArrayTypeBuilder::createEmpty();
        $impossibleBuilder->setOffsetValueType(
            new ConstantStringType('bad'),
            new NeverType(),
        );
        $viableBuilder = ConstantArrayTypeBuilder::createEmpty();
        $viableBuilder->setOffsetValueType(
            new ConstantStringType('valid'),
            new IntegerType(),
        );
        $viableType = $viableBuilder->getArray();
        $template = (new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeFactory',
            'create',
        ))->invoke(
            null,
            $scope,
            'T',
            new UnionType([$impossibleBuilder->getArray(), $viableType]),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(TemplateType::class, $template);

        $result = (new ArrayMergeType([$template]))->resolve();

        $this->assertTrue($viableType->equals($result), sprintf(
            'Expected %s, got %s',
            $viableType->describe(VerbosityLevel::precise()),
            $result->describe(VerbosityLevel::precise()),
        ));
    }

    public function testOptionalNeverPruningProducesDefiniteEmptyList(): void
    {
        $innerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $innerBuilder->setOffsetValueType(new ConstantStringType('ghost'), new NeverType(), true);
        $innerBuilder->setOffsetValueType(new ConstantStringType('phantom'), new NeverType(), true);

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerBuilder->getArray());

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertSame([], $resultInnerType->getKeyTypes());
        $this->assertTrue($resultInnerType->isList()->yes());
        $this->assertSame([0], $resultInnerType->getNextAutoIndexes());
    }

    public function testOptionalNeverPruningPreservesExplicitSealedMetadataForEmptyList(): void
    {
        $innerBuilder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($innerBuilder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($innerBuilder, 'makeUnsealed'));
            return;
        }

        $innerBuilder->setOffsetValueType(new ConstantStringType('ghost'), new NeverType(), true);
        $innerBuilder->makeUnsealed(new NeverType(true), new NeverType(true));
        $innerType = $innerBuilder->getArray();
        $this->assertInstanceOf(ConstantArrayType::class, $innerType);
        $this->assertTrue($innerType->isSealed()->yes());

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertSame([], $resultInnerType->getKeyTypes());
        $this->assertTrue($resultInnerType->isList()->yes());
        $this->assertSame([0], $resultInnerType->getNextAutoIndexes());
        $this->assertTrue($resultInnerType->isSealed()->yes());
    }

    public function testOptionalNeverPruningPreservesLargeConstantShape(): void
    {
        $keyTypes = [];
        $valueTypes = [];

        for ($i = 0; $i <= 256; $i++) {
            $keyTypes[] = new ConstantStringType('key' . $i);
            $valueTypes[] = new IntegerType();
        }

        $expectedInnerType = new ConstantArrayType($keyTypes, $valueTypes);
        $keyTypes[] = new ConstantStringType('ghost');
        $valueTypes[] = new NeverType();
        $innerType = new ConstantArrayType($keyTypes, $valueTypes, [0], [257]);

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertTrue($expectedInnerType->equals($resultInnerType));
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('key256'))->yes());
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('ghost'))->no());
    }

    public function testOptionalNeverPruningPreservesClosureShape(): void
    {
        $closureType = self::getContainer()->getByType(TypeStringResolver::class)->resolve('Closure(): int');
        $this->assertInstanceOf(ClosureType::class, $closureType);
        $keyTypes = [];
        $valueTypes = [];

        for ($i = 0; $i < 32; $i++) {
            $keyTypes[] = new ConstantStringType('callback' . $i);
            $valueTypes[] = $closureType;
        }

        $expectedInnerType = new ConstantArrayType($keyTypes, $valueTypes);
        $keyTypes[] = new ConstantStringType('ghost');
        $valueTypes[] = new NeverType();
        $innerType = new ConstantArrayType($keyTypes, $valueTypes, [0], [32]);

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertTrue($expectedInnerType->equals($resultInnerType));
        $this->assertCount(32, $resultInnerType->getKeyTypes());
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('callback0'))->yes());
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('callback31'))->yes());
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('ghost'))->no());
    }

    public function testOptionalNeverPruningPreservesExplicitSealedMetadata(): void
    {
        $innerBuilder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($innerBuilder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($innerBuilder, 'makeUnsealed'));
            return;
        }

        $innerBuilder->setOffsetValueType(new ConstantStringType('kept'), new IntegerType());
        $innerBuilder->setOffsetValueType(new ConstantStringType('ghost'), new NeverType(), true);
        $innerBuilder->makeUnsealed(new NeverType(true), new NeverType(true));
        $innerType = $innerBuilder->getArray();
        $this->assertInstanceOf(ConstantArrayType::class, $innerType);
        $this->assertTrue($innerType->isSealed()->yes());

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('ghost'))->no());
        $this->assertTrue($resultInnerType->isSealed()->yes());
    }

    public function testOptionalNeverPruningDoesNotPromoteNestedImplicitUnsealedMetadata(): void
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($builder, 'makeUnsealed')) {
            $this->assertFalse(self::hasOptionalMethod($builder, 'makeUnsealed'));
            return;
        }

        $builder->setOffsetValueType(new ConstantStringType('kept'), new IntegerType());
        $builder->setOffsetValueType(new ConstantStringType('ghost'), new NeverType(), true);
        $builder->makeUnsealed(new NeverType(), new NeverType());
        $innerType = $builder->getArray();
        $this->assertInstanceOf(ConstantArrayType::class, $innerType);
        $this->assertTrue($innerType->isUnsealed()->yes());

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerType);

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertTrue($resultInnerType->isUnsealed()->yes());
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('ghost'))->maybe());
    }

    public function testSetState(): void
    {
        $arrayType = new ArrayType(new MixedType(), new ObjectType('stdClass'));

        $this->assertInstanceOf(ErrorType::class, ArrayMergeType::__set_state([
            'types' => true,
        ]));

        $this->assertInstanceOf(ErrorType::class, ArrayMergeType::__set_state([
            'types' => [],
        ]));

        $this->assertInstanceOf(ErrorType::class, ArrayMergeType::__set_state([
            'types' => [true],
        ]));

        $result = ArrayMergeType::__set_state([
            'types' => ['array' => $arrayType],
        ]);

        $this->assertInstanceOf(ArrayMergeType::class, $result);
        $this->assertSame([$arrayType], $result->getTypes());
    }

    public function testToPhpDocNode(): void
    {
        $type = new ArrayMergeType([
            new ArrayType(new MixedType(), new ObjectType('stdClass')),
            new ArrayType(new MixedType(), new ObjectType('Throwable')),
        ]);

        $printer = new Printer();

        $this->assertSame('array-merge<array<stdClass>, array<Throwable>>', $printer->print($type->toPhpDocNode()));
    }

    public function testTraversePreservesEqualReplacementInstance(): void
    {
        $originalType = new IntegerType();
        $replacementType = new IntegerType();
        $type = new ArrayMergeType([$originalType]);

        $this->assertNotSame($originalType, $replacementType);
        $this->assertTrue($originalType->equals($replacementType));

        $result = $type->traverse(static fn(Type $type): Type => $replacementType);

        $this->assertInstanceOf(ArrayMergeType::class, $result);
        $this->assertNotSame($type, $result);
        $this->assertSame([$replacementType], $result->getTypes());
    }

    public function testOperandUnionTraversePreservesBenevolentReplacementSemantics(): void
    {
        $placeholder = new ArrayType(new MixedType(), new MixedType());
        $benevolentReplacement = new BenevolentUnionType([
            new ArrayType(new IntegerType(), new IntegerType()),
            new ArrayType(new StringType(), new StringType()),
        ]);
        $type = new ArrayMergeTypeOperandUnionType([
            $placeholder,
            new NeverType(),
        ]);

        $result = $type->traverse(static fn(Type $innerType): Type => $innerType === $placeholder
            ? $benevolentReplacement
            : $innerType);

        $this->assertInstanceOf(BenevolentUnionType::class, $result);
        $this->assertTrue($result->accepts(
            new ArrayType(new IntegerType(), new StringType()),
            true,
        )->no());
    }

    public function testOperandUnionTraversePreservesNestedTemplateUnion(): void
    {
        $emptyType = ConstantArrayTypeBuilder::createEmpty()->getArray();
        $aBuilder = ConstantArrayTypeBuilder::createEmpty();
        $aBuilder->setOffsetValueType(new ConstantStringType('a'), new IntegerType());
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, __FUNCTION__);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);
        $template = (new \ReflectionMethod('PHPStan\Type\Generic\TemplateTypeFactory', 'create'))->invoke(
            null,
            $scope,
            'U',
            new UnionType([$emptyType, $aBuilder->getArray()]),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(UnionType::class, $template);

        $cBuilder = ConstantArrayTypeBuilder::createEmpty();
        $cBuilder->setOffsetValueType(new ConstantStringType('c'), new IntegerType());
        $replacement = new UnionType([$template, $cBuilder->getArray()]);
        $nonEmpty = new IntersectionType([
            new ArrayType(new StringType(), new IntegerType()),
            new NonEmptyArrayType(),
        ]);
        $impossibleBuilder = ConstantArrayTypeBuilder::createEmpty();
        $impossibleBuilder->setOffsetValueType(new ConstantStringType('bad'), new NeverType());
        $placeholder = new ArrayType(new MixedType(), new MixedType());
        $type = new ArrayMergeTypeOperandUnionType([
            $placeholder,
            $nonEmpty,
            $impossibleBuilder->getArray(),
        ]);

        $result = $type->traverse(static fn(Type $innerType): Type => $innerType === $placeholder
            ? $replacement
            : $innerType);

        $this->assertTrue($result->isIterableAtLeastOnce()->maybe());
        $this->assertFalse((new ArrayMergeType([$result]))->isResolvable());
    }

    public function testOperandUnionSimultaneousTraversalPreservesNestedTemplateUnion(): void
    {
        $this->assertSimultaneousTraversalPreservesNestedTemplateUnion(false);
    }

    public function testBenevolentOperandUnionSimultaneousTraversalPreservesNestedTemplateUnion(): void
    {
        $this->assertSimultaneousTraversalPreservesNestedTemplateUnion(true);
    }

    public function testOperandUnionTraverseRestoresNestedBenevolentUnionAfterTemplateResolution(): void
    {
        $emptyType = ConstantArrayTypeBuilder::createEmpty()->getArray();
        $aBuilder = ConstantArrayTypeBuilder::createEmpty();
        $aBuilder->setOffsetValueType(new ConstantStringType('a'), new IntegerType());
        $aType = $aBuilder->getArray();
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, __FUNCTION__);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);
        $template = (new \ReflectionMethod('PHPStan\Type\Generic\TemplateTypeFactory', 'create'))->invoke(
            null,
            $scope,
            'U',
            new UnionType([$emptyType, $aType]),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(UnionType::class, $template);

        $cBuilder = ConstantArrayTypeBuilder::createEmpty();
        $cBuilder->setOffsetValueType(new ConstantStringType('c'), new StringType());
        $cType = $cBuilder->getArray();
        $replacement = new BenevolentUnionType([$template, $cType]);
        $placeholder = new ArrayType(new MixedType(), new MixedType());
        $type = new ArrayMergeTypeOperandUnionType([$placeholder, $cType]);

        $deferred = $type->traverse(static fn(Type $innerType): Type => $innerType === $placeholder
            ? $replacement
            : $innerType);
        $result = $deferred->traverse(static fn(Type $innerType): Type => $innerType === $template
            ? $aType
            : $innerType);
        $expected = TypeCombinator::union(
            $replacement->traverse(static fn(Type $innerType): Type => $innerType === $template
                ? $aType
                : $innerType),
            $cType,
        );

        $this->assertInstanceOf(BenevolentUnionType::class, $expected);
        $this->assertInstanceOf(BenevolentUnionType::class, $result);
        $this->assertTrue($expected->equals($result));
    }

    public function testTraverseReturnsOriginalWhenUnchanged(): void
    {
        $types = [new IntegerType(), new StringType()];
        $type = new ArrayMergeType($types);
        $visitedTypes = [];

        $result = $type->traverse(static function (Type $type) use (&$visitedTypes): Type {
            $visitedTypes[] = $type;

            return $type;
        });

        $this->assertSame($type, $result);
        $this->assertSame($types, $visitedTypes);
    }

    public function testTraverseSimultaneously(): void
    {
        $left = new ArrayMergeType([new IntegerType(), new StringType()]);
        $rightTypes = [new StringType(), new IntegerType()];
        $right = new ArrayMergeType($rightTypes);

        $result = $left->traverseSimultaneously(
            $right,
            static fn(Type $leftType, Type $rightType): Type => $rightType,
        );

        $this->assertInstanceOf(ArrayMergeType::class, $result);
        $this->assertNotSame($left, $result);
        $this->assertSame($rightTypes, $result->getTypes());
    }

    public function testTraverseSimultaneouslyReturnsOriginalWhenUnchanged(): void
    {
        $leftTypes = [new IntegerType(), new StringType()];
        $rightTypes = [new StringType(), new IntegerType()];
        $left = new ArrayMergeType($leftTypes);
        $right = new ArrayMergeType($rightTypes);
        $pairs = [];

        $result = $left->traverseSimultaneously(
            $right,
            static function (Type $leftType, Type $rightType) use (&$pairs): Type {
                $pairs[] = [$leftType, $rightType];

                return $leftType;
            },
        );

        $this->assertSame($left, $result);
        $this->assertSame([
            [$leftTypes[0], $rightTypes[0]],
            [$leftTypes[1], $rightTypes[1]],
        ], $pairs);
    }

    public function testTraverseSimultaneouslyIgnoresIncompatibleType(): void
    {
        $type = new ArrayMergeType([new IntegerType()]);
        $called = false;

        $result = $type->traverseSimultaneously(
            new MixedType(),
            static function (Type $leftType, Type $rightType) use (&$called): Type {
                $called = true;

                return $leftType;
            },
        );

        $this->assertSame($type, $result);
        $this->assertFalse($called);
    }

    public function testTraverseSimultaneouslyIgnoresDifferentArity(): void
    {
        $type = new ArrayMergeType([new IntegerType(), new StringType()]);
        $called = false;

        $result = $type->traverseSimultaneously(
            new ArrayMergeType([new IntegerType()]),
            static function (Type $leftType, Type $rightType) use (&$called): Type {
                $called = true;

                return $leftType;
            },
        );

        $this->assertSame($type, $result);
        $this->assertFalse($called);
    }

    private function assertSimultaneousTraversalPreservesNestedTemplateUnion(bool $benevolent): void
    {
        $emptyType = ConstantArrayTypeBuilder::createEmpty()->getArray();
        $aBuilder = ConstantArrayTypeBuilder::createEmpty();
        $aBuilder->setOffsetValueType(new ConstantStringType('a'), new IntegerType());
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, __FUNCTION__);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);
        $template = (new \ReflectionMethod('PHPStan\Type\Generic\TemplateTypeFactory', 'create'))->invoke(
            null,
            $scope,
            'T',
            new UnionType([$emptyType, $aBuilder->getArray()]),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(TemplateType::class, $template);

        $cBuilder = ConstantArrayTypeBuilder::createEmpty();
        $cBuilder->setOffsetValueType(new ConstantStringType('c'), new IntegerType());
        $replacement = $benevolent
            ? new BenevolentUnionType([$template, $cBuilder->getArray()])
            : new UnionType([$template, $cBuilder->getArray()]);
        $placeholder = new ArrayType(new MixedType(), new MixedType());
        $nonEmpty = new IntersectionType([
            new ArrayType(new StringType(), new IntegerType()),
            new NonEmptyArrayType(),
        ]);
        $impossibleBuilder = ConstantArrayTypeBuilder::createEmpty();
        $impossibleBuilder->setOffsetValueType(new ConstantStringType('bad'), new NeverType());
        $operandTypes = [$placeholder, $nonEmpty, $impossibleBuilder->getArray()];
        $type = $benevolent
            ? new ArrayMergeTypeOperandBenevolentUnionType($operandTypes)
            : new ArrayMergeTypeOperandUnionType($operandTypes);

        $result = $type->traverseSimultaneously(
            $placeholder,
            static fn(Type $leftType, Type $rightType): Type => $leftType === $placeholder
                ? $replacement
                : $leftType,
        );

        $this->assertFalse((new ArrayMergeType([$result]))->isResolvable());
    }

    private static function hasOptionalMethod(object $object, string $method): bool
    {
        return is_callable([$object, $method]);
    }
}
