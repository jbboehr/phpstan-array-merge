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

use Brick\VarExporter\VarExporter;
use jbboehr\PHPStan\ArrayMerge\ArrayMergeType;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ArrayType;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function array_map;
use function is_callable;
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

    public function testResolvePreservesExplicitMixedForMaybeArrayOperand(): void
    {
        $result = (new ArrayMergeType([new MixedType(true)]))->resolve();
        $arrays = $result->getArrays();

        $this->assertCount(1, $arrays);
        $this->assertInstanceOf(MixedType::class, $arrays[0]->getKeyType());
        $this->assertTrue($arrays[0]->getKeyType()->isExplicitMixed());
        $this->assertInstanceOf(MixedType::class, $arrays[0]->getItemType());
        $this->assertTrue($arrays[0]->getItemType()->isExplicitMixed());
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

    public function testOptionalNeverPruningPreservesLargeConstantShape(): void
    {
        $innerBuilder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($innerBuilder, 'disableArrayDegradation')) {
            $this->assertFalse(self::hasOptionalMethod($innerBuilder, 'disableArrayDegradation'));
            return;
        }

        $innerBuilder->disableArrayDegradation();

        for ($i = 0; $i <= 256; $i++) {
            $innerBuilder->setOffsetValueType(new ConstantStringType('key' . $i), new IntegerType());
        }

        $innerBuilder->setOffsetValueType(new ConstantStringType('ghost'), new NeverType(), true);

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerBuilder->getArray());

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('key256'))->yes());
        $this->assertTrue($resultInnerType->hasOffsetValueType(new ConstantStringType('ghost'))->no());
    }

    public function testOptionalNeverPruningPreservesClosureShape(): void
    {
        $innerBuilder = ConstantArrayTypeBuilder::createEmpty();

        if (!self::hasOptionalMethod($innerBuilder, 'disableArrayDegradation')) {
            $this->assertFalse(self::hasOptionalMethod($innerBuilder, 'disableArrayDegradation'));
            return;
        }

        $innerBuilder->disableArrayDegradation();
        $closureType = self::getContainer()->getByType(TypeStringResolver::class)->resolve('Closure(): int');
        $this->assertInstanceOf(ClosureType::class, $closureType);

        for ($i = 0; $i < 32; $i++) {
            $innerBuilder->setOffsetValueType(new ConstantStringType('callback' . $i), $closureType);
        }

        $innerBuilder->setOffsetValueType(new ConstantStringType('ghost'), new NeverType(), true);

        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $innerBuilder->getArray());

        $result = (new ArrayMergeType([$outerBuilder->getArray()]))->resolve();
        $resultInnerType = $result->getOffsetValueType(new ConstantStringType('outer'));

        $this->assertInstanceOf(ConstantArrayType::class, $resultInnerType);
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

    public function testVarExporterRoundTrip(): void
    {
        $type = new ArrayMergeType([new MixedType(true)]);
        $expectedResult = $type->resolve();

        /** @var mixed $restored */
        $restored = eval('return ' . VarExporter::export($type) . ';');

        $this->assertInstanceOf(ArrayMergeType::class, $restored);
        $restoredOperand = $restored->getTypes()[0];
        $this->assertInstanceOf(MixedType::class, $restoredOperand);
        $this->assertTrue($restoredOperand->isExplicitMixed());
        $this->assertTrue($type->equals($restored));
        $this->assertTrue($expectedResult->equals($restored->resolve()));
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

    private static function hasOptionalMethod(object $object, string $method): bool
    {
        return is_callable([$object, $method]);
    }
}
