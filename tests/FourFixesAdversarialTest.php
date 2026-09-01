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
use jbboehr\PHPStan\ArrayMerge\ArrayMergeTypeOperandBenevolentUnionType;
use jbboehr\PHPStan\ArrayMerge\ArrayMergeTypeOperandUnionType;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\SimultaneousTypeTraverser;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;

final class FourFixesAdversarialTest extends TemplateTypeTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function impossibleArrayProvider(): iterable
    {
        yield 'impossible branch first' => [
            'array-merge<non-empty-array<never, string>|array{valid: int}>',
            'array{valid: int}',
        ];
        yield 'impossible branch last' => [
            'array-merge<array{valid: int}|non-empty-array<never, string>>',
            'array{valid: int}',
        ];
        yield 'nested optional impossible value' => [
            'array-merge<array{outer?: non-empty-array<int, never>}>',
            'array{}',
        ];
    }

    #[DataProvider('impossibleArrayProvider')]
    public function testImpossibleArraysAreNeutralPerUnionBranch(
        string $mergeType,
        string $expectedType,
    ): void {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($mergeType);
        $expected = $resolver->resolve($expectedType);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $actual = $merge->resolve();

        $this->assertTrue($expected->equals($actual), sprintf(
            'Expected %s, got %s',
            $expected->describe(VerbosityLevel::precise()),
            $actual->describe(VerbosityLevel::precise()),
        ));
    }

    public function testNestedImpossibleGenericArrayUnionBranchIsNeutral(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve(
            'array-merge<array{outer: non-empty-array<int, never>|array{valid: int}}>',
        );
        $expected = $resolver->resolve('array{outer: array{valid: int}}');
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $actual = $merge->resolve();

        $this->assertTrue($expected->equals($actual), sprintf(
            'Expected %s, got %s',
            $expected->describe(VerbosityLevel::precise()),
            $actual->describe(VerbosityLevel::precise()),
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestedAllImpossibleUnionProvider(): iterable
    {
        yield 'required union' => [
            'array-merge<array{outer: non-empty-array<never, string>|non-empty-array<int, never>}>',
            'never',
        ];
        yield 'optional union' => [
            'array-merge<array{outer?: non-empty-array<never, string>|non-empty-array<int, never>}>',
            'array{}',
        ];
    }

    #[DataProvider('nestedAllImpossibleUnionProvider')]
    public function testNestedAllImpossibleUnionHonorsOptionality(
        string $mergeType,
        string $expectedType,
    ): void {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($mergeType);
        $expected = $resolver->resolve($expectedType);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertTrue($expected->equals($merge->resolve()));
    }

    public function testNestedImpossibleUnionLeavesShorthandArrayBranch(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve(
            'array-merge<array{outer: non-empty-array<never, string>|int[]}>',
        );
        $expected = $resolver->resolve('array{outer: int[]}');
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertTrue($expected->equals($merge->resolve()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recursivelyImpossibleArrayProvider(): iterable
    {
        yield 'deep required impossible value' => [
            'array-merge<array{outer: array{inner: non-empty-array<int, never>}}>',
        ];
        yield 'impossible value makes non-empty generic parent impossible' => [
            'array-merge<non-empty-array<string, non-empty-array<never, string>>>',
        ];
        yield 'two impossible branches in reverse key-value order' => [
            'array-merge<non-empty-array<int, never>|non-empty-array<never, string>>',
        ];
    }

    #[DataProvider('recursivelyImpossibleArrayProvider')]
    public function testImpossibleArraysPropagateRecursively(string $mergeType): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($mergeType);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertInstanceOf(NeverType::class, $merge->resolve());
    }

    public function testImpossibleArrayTemplateBoundTerminatesAfterResolvingToBounds(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $bound = $resolver->resolve('non-empty-array<never, string>');
        $template = $this->createTemplate('impossibleBound', 'T', $bound);
        $merge = $resolver->resolve(
            'array-merge<T>',
            new NameScope(
                null,
                [],
                null,
                'impossibleBound',
                new TemplateTypeMap(['T' => $template]),
            ),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $this->assertFalse($merge->isResolvable());

        $resolvedToBounds = $this->resolveToBounds($merge);

        $this->assertInstanceOf(ArrayMergeType::class, $resolvedToBounds);
        $this->assertInstanceOf(NeverType::class, $resolvedToBounds->resolve());
    }

    public function testNestedImpossibleArrayTemplateBoundDoesNotWidenToMixed(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $template = $this->createTemplate(
            'nestedImpossibleBound',
            'T',
            $resolver->resolve('non-empty-array<never, string>'),
        );
        $merge = $resolver->resolve(
            'array-merge<array{outer: T}>',
            new NameScope(
                null,
                [],
                null,
                'nestedImpossibleBound',
                new TemplateTypeMap(['T' => $template]),
            ),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $this->assertFalse($merge->isResolvable());

        $this->assertInstanceOf(NeverType::class, $merge->resolve());
    }

    public function testNestedOptionalImpossibleArrayTemplateBoundIsPruned(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $template = $this->createTemplate(
            'nestedOptionalImpossibleBound',
            'T',
            $resolver->resolve('non-empty-array<never, string>'),
        );
        $merge = $resolver->resolve(
            'array-merge<array{outer: array{inner?: T}}>',
            new NameScope(
                null,
                [],
                null,
                'nestedOptionalImpossibleBound',
                new TemplateTypeMap(['T' => $template]),
            ),
        );
        $expected = $resolver->resolve('array{outer: array{}}');
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $this->assertFalse($merge->isResolvable());

        $this->assertTrue($expected->equals($merge->resolve()));
    }

    public function testNestedImpossibleBranchStaysNeutralAfterFinalTemplateResolution(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $template = $this->createTemplate('nestedFinal', 'T', $resolver->resolve('array'));
        $merge = $resolver->resolve(
            'array-merge<array{outer: T|non-empty-array<int, never>|array{valid: int}}>',
            new NameScope(
                null,
                [],
                null,
                'nestedFinal',
                new TemplateTypeMap(['T' => $template]),
            ),
        );
        $expected = $resolver->resolve('array{outer: array{valid: int}}');
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $this->assertFalse($merge->isResolvable());

        $specialized = $this->resolveTemplateTypes($merge, $template, new NeverType());
        $this->assertInstanceOf(ArrayMergeType::class, $specialized);
        $actual = $specialized->resolve();

        $this->assertTrue($expected->equals($actual), sprintf(
            'Expected %s, got %s',
            $expected->describe(VerbosityLevel::precise()),
            $actual->describe(VerbosityLevel::precise()),
        ));
    }

    public function testTopLevelTemplateResolutionBoundary(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $mixedTemplate = $this->createTemplate('boundary', 'T', new MixedType());
        $arrayTemplate = $this->createTemplate('boundary', 'U', $resolver->resolve('array'));
        $nameScope = new NameScope(
            null,
            [],
            null,
            'boundary',
            new TemplateTypeMap([
                'T' => $mixedTemplate,
                'U' => $arrayTemplate,
            ]),
        );
        $mixedMerge = $resolver->resolve('array-merge<array{valid: int}|T>', $nameScope);
        $arrayMerge = $resolver->resolve('array-merge<array{valid: int}|U>', $nameScope);
        $this->assertInstanceOf(ArrayMergeType::class, $mixedMerge);
        $this->assertInstanceOf(ArrayMergeType::class, $arrayMerge);

        $this->assertTrue($mixedMerge->isResolvable());
        $this->assertInstanceOf(ErrorType::class, $mixedMerge->resolve());
        $this->assertFalse($arrayMerge->isResolvable());
        $this->assertTrue(TypeUtils::containsTemplateType($arrayMerge));
    }

    public function testScalarForwardedTemplateSurvivesRoundTripUntilSpecialization(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $innerTemplate = $this->createTemplate('inner', 'T', new IntegerType());
        $forwardedTemplate = $this->createTemplate('forwarder', 'U', new IntegerType());
        $innerScope = new NameScope(
            null,
            [],
            null,
            'inner',
            new TemplateTypeMap(['T' => $innerTemplate]),
        );
        $merge = $resolver->resolve('array-merge<array{value: T|array-key}>', $innerScope);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $deferred = $this->resolveTemplateTypes($merge, $innerTemplate, $forwardedTemplate);
        $this->assertInstanceOf(ArrayMergeType::class, $deferred);
        $this->assertTrue(TypeUtils::containsTemplateType($deferred));
        $this->assertTrue($this->containsIdenticalType($deferred, $forwardedTemplate));

        $printed = (new Printer())->print($deferred->toPhpDocNode());
        $roundTrip = $resolver->resolve(
            $printed,
            new NameScope(
                null,
                [],
                null,
                'forwarder',
                new TemplateTypeMap(['U' => $forwardedTemplate]),
            ),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $roundTrip);
        $this->assertTrue($this->containsIdenticalType($roundTrip, $forwardedTemplate));

        $specialized = $this->resolveTemplateTypes(
            $roundTrip,
            $forwardedTemplate,
            new ConstantIntegerType(1),
        );

        $this->assertInstanceOf(ArrayMergeType::class, $specialized);
        $this->assertFalse(TypeUtils::containsTemplateType($specialized));
        $this->assertTrue($specialized->isResolvable());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function operandUnionKindProvider(): iterable
    {
        yield 'ordinary operand union' => [false];
        yield 'benevolent operand union' => [true];
    }

    #[DataProvider('operandUnionKindProvider')]
    public function testOperandWrapperKeepsImpossibleBranchSeparateThroughFinalSpecialization(
        bool $benevolent,
    ): void {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $template = $this->createTemplate('wrapperFinal', 'T', $resolver->resolve('array'));
        $impossible = $resolver->resolve('non-empty-array<int, never>');
        $valid = $resolver->resolve('array{valid: int}');
        $wrapperTypes = [$template, $impossible, $valid];
        $wrapper = $benevolent
            ? new ArrayMergeTypeOperandBenevolentUnionType($wrapperTypes)
            : new ArrayMergeTypeOperandUnionType($wrapperTypes);
        $outerBuilder = ConstantArrayTypeBuilder::createEmpty();
        $outerBuilder->setOffsetValueType(new ConstantStringType('outer'), $wrapper);
        $merge = new ArrayMergeType([$outerBuilder->getArray()]);
        $expected = $resolver->resolve('array{outer: array{valid: int}}');
        $this->assertFalse($merge->isResolvable());

        $specialized = $this->resolveTemplateTypes($merge, $template, new NeverType());
        $this->assertInstanceOf(ArrayMergeType::class, $specialized);
        $actual = $specialized->resolve();

        $this->assertTrue($expected->equals($actual), sprintf(
            'Expected %s, got %s',
            $expected->describe(VerbosityLevel::precise()),
            $actual->describe(VerbosityLevel::precise()),
        ));
    }

    #[DataProvider('operandUnionKindProvider')]
    public function testSimultaneousTraversalMatchesStagedOrdinaryTraversal(bool $benevolent): void
    {
        $empty = ConstantArrayTypeBuilder::createEmpty()->getArray();
        $aBuilder = ConstantArrayTypeBuilder::createEmpty();
        $aBuilder->setOffsetValueType(new ConstantStringType('a'), new IntegerType());
        $a = $aBuilder->getArray();
        $template = $this->createTemplate(
            'simultaneous',
            'T',
            new UnionType([$empty, $a]),
        );
        $cBuilder = ConstantArrayTypeBuilder::createEmpty();
        $cBuilder->setOffsetValueType(new ConstantStringType('c'), new IntegerType());
        $c = $cBuilder->getArray();
        $replacement = $benevolent
            ? new BenevolentUnionType([$template, $c])
            : new UnionType([$template, $c]);
        $placeholder = new ArrayType(new MixedType(), new MixedType());
        $nonEmpty = new IntersectionType([
            new ArrayType(new StringType(), new IntegerType()),
            new NonEmptyArrayType(),
        ]);
        $impossibleBuilder = ConstantArrayTypeBuilder::createEmpty();
        $impossibleBuilder->setOffsetValueType(new ConstantStringType('bad'), new NeverType());
        $leftTypes = [$placeholder, $nonEmpty, $impossibleBuilder->getArray()];
        $left = $benevolent
            ? new ArrayMergeTypeOperandBenevolentUnionType($leftTypes)
            : new ArrayMergeTypeOperandUnionType($leftTypes);
        $replacePlaceholder = static fn(Type $type): Type => $type === $placeholder
            ? $replacement
            : $type;

        $ordinary = $left->traverse($replacePlaceholder);
        $simultaneous = SimultaneousTypeTraverser::map(
            $left,
            $placeholder,
            static function (
                Type $declared,
                Type $actual,
                callable $traverse,
            ) use (
                $placeholder,
                $replacement,
            ): Type {
                if ($declared === $placeholder) {
                    return $replacement;
                }

                return $traverse($declared, $actual);
            },
        );

        $this->assertTrue(TypeUtils::containsTemplateType($ordinary));
        $this->assertTrue(TypeUtils::containsTemplateType($simultaneous));
        $this->assertTrue(
            $ordinary->isIterableAtLeastOnce()->equals($simultaneous->isIterableAtLeastOnce()),
        );

        foreach ([$empty, $a] as $specialization) {
            $ordinarySpecialized = $this->resolveTemplateTypes($ordinary, $template, $specialization);
            $simultaneousSpecialized = $this->resolveTemplateTypes(
                $simultaneous,
                $template,
                $specialization,
            );
            $ordinaryResult = (new ArrayMergeType([$ordinarySpecialized]))->resolve();
            $simultaneousResult = (new ArrayMergeType([$simultaneousSpecialized]))->resolve();

            $this->assertTrue($ordinaryResult->equals($simultaneousResult), sprintf(
                'Ordinary traversal produced %s, simultaneous traversal produced %s',
                $ordinaryResult->describe(VerbosityLevel::precise()),
                $simultaneousResult->describe(VerbosityLevel::precise()),
            ));
        }
    }

    #[DataProvider('operandUnionKindProvider')]
    public function testSimultaneousTraversalSkipsTemplateRightHand(
        bool $benevolent,
    ): void {
        $empty = ConstantArrayTypeBuilder::createEmpty()->getArray();
        $aBuilder = ConstantArrayTypeBuilder::createEmpty();
        $aBuilder->setOffsetValueType(new ConstantStringType('a'), new IntegerType());
        $template = $this->createTemplate(
            'simultaneousRight',
            'T',
            new UnionType([$empty, $aBuilder->getArray()]),
        );
        $array = new ArrayType(new MixedType(), new MixedType());
        $types = [$array, new NeverType()];
        $wrapper = $benevolent
            ? new ArrayMergeTypeOperandBenevolentUnionType($types)
            : new ArrayMergeTypeOperandUnionType($types);
        $called = false;

        $result = $wrapper->traverseSimultaneously(
            $template,
            static function (Type $left, Type $right) use (&$called): Type {
                $called = true;

                return $right;
            },
        );

        $this->assertSame($wrapper, $result);
        $this->assertFalse($called);
    }

    #[DataProvider('operandUnionKindProvider')]
    public function testSimultaneousTraversalSkipsUnionContainingTemplate(
        bool $benevolent,
    ): void {
        $empty = ConstantArrayTypeBuilder::createEmpty()->getArray();
        $aBuilder = ConstantArrayTypeBuilder::createEmpty();
        $aBuilder->setOffsetValueType(new ConstantStringType('a'), new IntegerType());
        $template = $this->createTemplate(
            'simultaneousMultipleRight',
            'T',
            new UnionType([$empty, $aBuilder->getArray()]),
        );
        $array = new ArrayType(new MixedType(), new MixedType());
        $wrapperTypes = [$array, new NeverType()];
        $wrapper = $benevolent
            ? new ArrayMergeTypeOperandBenevolentUnionType($wrapperTypes)
            : new ArrayMergeTypeOperandUnionType($wrapperTypes);
        $cBuilder = ConstantArrayTypeBuilder::createEmpty();
        $cBuilder->setOffsetValueType(new ConstantStringType('c'), new IntegerType());
        $c = $cBuilder->getArray();
        $right = new UnionType([$template, $c]);
        $calls = 0;
        $this->assertTrue($this->containsIdenticalType($right, $template));

        $result = $wrapper->traverseSimultaneously(
            $right,
            static function (Type $left, Type $right) use (&$calls): Type {
                $calls++;

                return $right;
            },
        );

        $this->assertSame($wrapper, $result);
        $this->assertSame(0, $calls);
    }

    private function containsIdenticalType(Type $type, Type $expected): bool
    {
        $found = false;
        TypeTraverser::map(
            $type,
            static function (Type $type, callable $traverse) use ($expected, &$found): Type {
                if ($type === $expected) {
                    $found = true;
                }

                return $traverse($type);
            },
        );

        return $found;
    }
}
