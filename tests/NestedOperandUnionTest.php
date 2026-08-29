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
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\Generic\TemplateTypeVarianceMap;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;

final class NestedOperandUnionTest extends PHPStanTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nestedUnionProvider(): iterable
    {
        yield 'boolean alternatives' => ['true|false'];
        yield 'duplicate alternatives' => ['int|int'];
        yield 'never alternative' => ['never|string'];
        yield 'parenthesized alternatives' => ['(int|string)|bool'];
        yield 'benevolent array-key alias' => ['array-key|int'];
        yield 'benevolent alias with another alternative' => ['scalar|resource'];
        yield 'array shorthand with broad array' => ['array|int[]'];
    }

    #[DataProvider('nestedUnionProvider')]
    public function testSingleShapeMergePreservesNestedUnionSemantics(string $valueType): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $operand = $resolver->resolve(sprintf('array{value: %s}', $valueType));
        $merge = $resolver->resolve(sprintf('array-merge<array{value: %s}>', $valueType));

        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();
        $this->assertTrue($operand->equals($result), sprintf(
            'Direct %s (%s), merged %s (%s)',
            $operand->describe(VerbosityLevel::precise()),
            get_debug_type($operand),
            $result->describe(VerbosityLevel::precise()),
            get_debug_type($result),
        ));
    }

    public function testImpossibleShapeIsNeutralThroughDeepTemplateForwarding(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $bound = $resolver->resolve('array{}|array{a: int}');
        $innerTemplate = $this->createTemplate('inner', 'T', $bound);
        $forwardedTemplate = $this->createTemplate('forwarder', 'U', $bound);
        $nameScope = new NameScope(
            null,
            [],
            null,
            'inner',
            new TemplateTypeMap(['T' => $innerTemplate]),
        );

        $control = $resolver->resolve(
            'array-merge<array{outer: array{inner: T|non-empty-array<string, int>}}>',
            $nameScope,
        );
        $withImpossibleShape = $resolver->resolve(
            'array-merge<array{outer: array{inner: T|non-empty-array<string, int>|array{bad: never}}}>',
            $nameScope,
        );
        $this->assertInstanceOf(ArrayMergeType::class, $control);
        $this->assertInstanceOf(ArrayMergeType::class, $withImpossibleShape);

        $forwardedControl = $this->resolveTemplateTypes($control, $innerTemplate, $forwardedTemplate);
        $forwardedWithImpossibleShape = $this->resolveTemplateTypes(
            $withImpossibleShape,
            $innerTemplate,
            $forwardedTemplate,
        );
        $this->assertInstanceOf(ArrayMergeType::class, $forwardedControl);
        $this->assertInstanceOf(ArrayMergeType::class, $forwardedWithImpossibleShape);
        $this->assertFalse($forwardedControl->isResolvable());
        $this->assertFalse($forwardedWithImpossibleShape->isResolvable());

        $resolveToBounds = new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeHelper',
            'resolveToBounds',
        );
        $controlResult = $resolveToBounds->invoke(null, $forwardedControl);
        $resultWithImpossibleShape = $resolveToBounds->invoke(null, $forwardedWithImpossibleShape);
        $this->assertInstanceOf(ArrayMergeType::class, $controlResult);
        $this->assertInstanceOf(ArrayMergeType::class, $resultWithImpossibleShape);
        $this->assertTrue($controlResult->resolve()->equals($resultWithImpossibleShape->resolve()));
        $this->assertSame(
            'array{outer: array{inner: array<string, int>}}',
            $resultWithImpossibleShape->resolve()->describe(VerbosityLevel::precise()),
        );
    }

    public function testTemplateForwardingPreservesBenevolentUnionSemantics(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $template = $this->createTemplate('inner', 'T', new MixedType());
        $nameScope = new NameScope(
            null,
            [],
            null,
            'inner',
            new TemplateTypeMap(['T' => $template]),
        );
        $merge = $resolver->resolve('array-merge<array{value: T|array-key}>', $nameScope);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $forwarded = $this->resolveTemplateTypes($merge, $template, new IntegerType());
        $this->assertInstanceOf(ArrayMergeType::class, $forwarded);
        $actualValueType = $forwarded->resolve()->getIterableValueType();
        $expectedValueType = $resolver->resolve('array{value: int|array-key}')->getIterableValueType();

        $this->assertInstanceOf(BenevolentUnionType::class, $expectedValueType);
        $this->assertInstanceOf(BenevolentUnionType::class, $actualValueType);
        $this->assertTrue($expectedValueType->equals($actualValueType));
        $this->assertTrue($actualValueType->isAcceptedBy(new IntegerType(), true)->yes());

        $forwardedBoolean = $this->resolveTemplateTypes($merge, $template, new BooleanType());
        $this->assertInstanceOf(ArrayMergeType::class, $forwardedBoolean);
        $actualBooleanValueType = $forwardedBoolean->resolve()->getIterableValueType();
        $expectedBooleanValueType = $resolver->resolve('array{value: bool|array-key}')->getIterableValueType();
        $this->assertNotInstanceOf(BenevolentUnionType::class, $actualBooleanValueType);
        $this->assertTrue($expectedBooleanValueType->equals($actualBooleanValueType));

        $forwardedTemplate = $this->createTemplate('forwarder', 'U', new MixedType());
        $deferred = $this->resolveTemplateTypes($merge, $template, $forwardedTemplate);
        $this->assertInstanceOf(ArrayMergeType::class, $deferred);
        $this->assertFalse($deferred->isResolvable());
        $forwardedThroughTemplate = $this->resolveTemplateTypes(
            $deferred,
            $forwardedTemplate,
            new IntegerType(),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $forwardedThroughTemplate);
        $forwardedValueType = $forwardedThroughTemplate->resolve()->getIterableValueType();
        $this->assertTrue(
            $expectedValueType->equals($forwardedValueType),
            $forwardedValueType->describe(VerbosityLevel::precise()),
        );

        $secondTemplate = $this->createTemplate('inner', 'U', $resolver->resolve('1|2'));
        $twoTemplateScope = new NameScope(
            null,
            [],
            null,
            'inner',
            new TemplateTypeMap([
                'T' => $template,
                'U' => $secondTemplate,
            ]),
        );
        $twoTemplateMerge = $resolver->resolve(
            'array-merge<array{value: T|U|array-key}>',
            $twoTemplateScope,
        );
        $this->assertInstanceOf(ArrayMergeType::class, $twoTemplateMerge);
        $partiallyForwarded = $this->resolveTemplateTypes(
            $twoTemplateMerge,
            $template,
            new IntegerType(),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $partiallyForwarded);
        $this->assertFalse($partiallyForwarded->isResolvable());
        $fullyForwarded = $this->resolveTemplateTypes(
            $partiallyForwarded,
            $secondTemplate,
            $resolver->resolve('1'),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $fullyForwarded);
        $this->assertTrue($expectedValueType->equals(
            $fullyForwarded->resolve()->getIterableValueType(),
        ));
    }

    private function createTemplate(string $functionName, string $name, Type $bound): TemplateType
    {
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, $functionName);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);
        $template = (new \ReflectionMethod('PHPStan\Type\Generic\TemplateTypeFactory', 'create'))->invoke(
            null,
            $scope,
            $name,
            $bound,
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(TemplateType::class, $template);

        return $template;
    }

    private function resolveTemplateTypes(Type $type, TemplateType $from, Type $to): Type
    {
        $resolveTemplateTypes = new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeHelper',
            'resolveTemplateTypes',
        );
        $result = $resolveTemplateTypes->invoke(
            null,
            $type,
            new TemplateTypeMap([$from->getName() => $to]),
            TemplateTypeVarianceMap::createEmpty(),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(Type::class, $result);

        return $result;
    }
}
