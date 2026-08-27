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
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\Type\ArrayType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\TestCase;

final class ArrayMergeTypeTest extends TestCase
{
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

    public function testEquals(): void
    {
        $left = new ArrayMergeType([new MixedType()]);
        $right = new ArrayMergeType([new IntegerType()]);

        $multiLeft = new ArrayMergeType([new IntegerType(), new StringType()]);
        $multiRight = new ArrayMergeType([new IntegerType(), new StringType()]);

        $this->assertFalse($left->equals($right));
        $this->assertFalse($right->equals($left));
        $this->assertTrue($multiLeft->equals($multiRight));
        $this->assertTrue($multiRight->equals($multiLeft));
        $this->assertFalse($left->equals($multiLeft));
        $this->assertFalse($multiLeft->equals($left));
        $this->assertFalse($left->equals(new IntegerType()));
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

    public function testSetState(): void
    {
        $this->assertInstanceOf(ErrorType::class, ArrayMergeType::__set_state([
            'types' => true,
        ]));

        $this->assertInstanceOf(ErrorType::class, ArrayMergeType::__set_state([
            'types' => [],
        ]));

        $this->assertInstanceOf(ErrorType::class, ArrayMergeType::__set_state([
            'types' => [true],
        ]));

        $this->assertInstanceOf(ArrayMergeType::class, ArrayMergeType::__set_state([
            'types' => [new ArrayType(new MixedType(), new ObjectType('stdClass'))],
        ]));
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
}
