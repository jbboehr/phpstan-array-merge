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

use jbboehr\PHPStan\ArrayMerge\ArrayMergeTypeOperandBenevolentUnionType;
use jbboehr\PHPStan\ArrayMerge\ArrayMergeTypeOperandUnionType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\NeverType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPUnit\Framework\Attributes\DataProvider;

final class OperandUnionTraversalTest extends TemplateTypeTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function wrapperKindProvider(): iterable
    {
        yield 'ordinary operand union' => [false];
        yield 'benevolent operand union' => [true];
    }

    #[DataProvider('wrapperKindProvider')]
    public function testNoOpTraversalVisitsEveryOperandAndRetainsIdentity(bool $benevolent): void
    {
        $first = IntegerRangeType::fromInterval(0, 10);
        $second = IntegerRangeType::fromInterval(5, 15);
        $wrapper = $this->createWrapper($benevolent, [$first, $second]);
        $visited = [];

        $result = $wrapper->traverse(static function (Type $type) use (&$visited): Type {
            $visited[] = $type;

            return $type;
        });

        $this->assertSame($wrapper, $result);
        $this->assertSame([$first, $second], $visited);
    }

    public function testOrdinaryWrapperNoOpTraversalUsesOriginalSourceGrouping(): void
    {
        $integer = new ConstantIntegerType(1);
        $string = new ConstantStringType('value');
        $sourceUnion = new UnionType([$integer, $string]);
        $wrapper = new ArrayMergeTypeOperandUnionType(
            [$integer, $string],
            [$sourceUnion],
        );
        $visited = [];

        $result = $wrapper->traverse(static function (Type $type) use (&$visited): Type {
            $visited[] = $type;

            return $type;
        });

        $this->assertSame($wrapper, $result);
        $this->assertSame([$sourceUnion], $visited);
    }

    #[DataProvider('wrapperKindProvider')]
    public function testSimultaneousTraversalConsumesGroupedCandidatesOnceAndIgnoresUnmatched(
        bool $benevolent,
    ): void {
        $first = IntegerRangeType::fromInterval(0, 10);
        $second = IntegerRangeType::fromInterval(5, 15);
        $wrapper = $this->createWrapper($benevolent, [$first, $second]);
        $one = new ConstantIntegerType(1);
        $seven = new ConstantIntegerType(7);
        $twelve = new ConstantIntegerType(12);
        $unmatched = new ConstantStringType('unmatched');
        $right = new UnionType([$one, $seven, $twelve, $unmatched]);
        $pairs = [];

        $result = $wrapper->traverseSimultaneously(
            $right,
            static function (Type $left, Type $right) use (&$pairs): Type {
                $pairs[] = [$left, $right];

                return $left;
            },
        );

        $this->assertSame($wrapper, $result);
        $this->assertCount(2, $pairs);
        $this->assertSame($first, $pairs[0][0]);
        $this->assertTrue(TypeCombinator::union($one, $seven)->equals($pairs[0][1]));
        $this->assertSame($second, $pairs[1][0]);
        $this->assertTrue($twelve->equals($pairs[1][1]));
    }

    #[DataProvider('wrapperKindProvider')]
    public function testReplacementFlattensOrdinaryUnionButPreservesTemplateUnion(
        bool $benevolent,
    ): void {
        $template = $this->createTemplate(
            'flattenReplacement',
            'T',
            new UnionType([
                new ConstantIntegerType(1),
                new ConstantIntegerType(2),
            ]),
        );
        $string = new StringType();
        $ordinaryUnion = new UnionType([$template, $string]);
        $placeholder = new BooleanType();
        $wrapper = $this->createWrapper($benevolent, [$placeholder, new NeverType()]);

        $result = $wrapper->traverse(static fn(Type $type): Type => $type === $placeholder
            ? $ordinaryUnion
            : $type);

        $this->assertNotSame($wrapper, $result);
        $this->assertInstanceOf(
            $benevolent
                ? ArrayMergeTypeOperandBenevolentUnionType::class
                : ArrayMergeTypeOperandUnionType::class,
            $result,
        );
        $this->assertFalse(in_array($ordinaryUnion, $result->getTypes(), true));
        $this->assertTrue(in_array($template, $result->getTypes(), true));
        $this->assertTrue($this->containsEqualType($result->getTypes(), $string));
    }

    /**
     * @param non-empty-list<Type> $types
     */
    private function createWrapper(bool $benevolent, array $types): UnionType
    {
        return $benevolent
            ? new ArrayMergeTypeOperandBenevolentUnionType($types)
            : new ArrayMergeTypeOperandUnionType($types);
    }

    /**
     * @param list<Type> $types
     */
    private function containsEqualType(array $types, Type $expected): bool
    {
        foreach ($types as $type) {
            if ($expected->equals($type)) {
                return true;
            }
        }

        return false;
    }
}
