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
use jbboehr\PHPStan\ArrayMerge\ShouldNotHappenException;
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

    public function testTraverseSimultaneously(): void
    {
        $this->expectException(ShouldNotHappenException::class);

        $type = new ArrayMergeType([
            new ArrayType(new MixedType(), new ObjectType('stdClass')),
            new ArrayType(new MixedType(), new ObjectType('Throwable')),
        ]);

        $type->traverseSimultaneously(new MixedType(), static fn(Type $type): MixedType => new MixedType());
    }
}
