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
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\Generic\TemplateTypeVarianceMap;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

final class NormalizedTemplateBoundAdversarialTest extends PHPStanTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }

    public function testNestedTemplateBoundCanBeSpecializedAfterPriorInference(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $template = $this->createTemplate(
            'nestedSpecialization',
            'T',
            $resolver->resolve('array{bad: never}|array{valid: int}'),
        );
        $merge = $resolver->resolve(
            'array-merge<array{nested: T}, array{tail: string}>',
            $this->createNameScope('nestedSpecialization', ['T' => $template]),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertTypeEquals(
            $resolver->resolve('array{nested: array{valid: int}, tail: string}'),
            $merge->resolve(),
        );

        $specialized = $this->resolveTemplateTypes(
            $merge,
            $template,
            $resolver->resolve('array{valid: 1}'),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $specialized);
        $this->assertTypeEquals(
            $resolver->resolve('array{nested: array{valid: 1}, tail: string}'),
            $specialized->resolve(),
        );
    }

    public function testMultipleTemplateOperandsNormalizeTheirBoundsIndependently(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $left = $this->createTemplate(
            'multipleOperands',
            'TLeft',
            $resolver->resolve('array{leftBad: never}|array{left: int}'),
        );
        $right = $this->createTemplate(
            'multipleOperands',
            'TRight',
            $resolver->resolve('array{rightBad: never}|array{right: string}'),
        );
        $merge = $resolver->resolve(
            'array-merge<TLeft, TRight>',
            $this->createNameScope('multipleOperands', [
                'TLeft' => $left,
                'TRight' => $right,
            ]),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertTypeEquals(
            $resolver->resolve('array{left: int, right: string}'),
            $merge->resolve(),
        );

        $specialized = $this->resolveTemplateTypes(
            $merge,
            $left,
            $resolver->resolve('array{left: 1}'),
        );
        $specialized = $this->resolveTemplateTypes(
            $specialized,
            $right,
            $resolver->resolve("array{right: 'selected'}"),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $specialized);
        $this->assertTypeEquals(
            $resolver->resolve("array{left: 1, right: 'selected'}"),
            $specialized->resolve(),
        );
    }

    public function testPartiallyImpossibleListBoundPreservesNonEmptyInferenceAndSpecialization(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $direct = $resolver->resolve(
            'array-merge<array{bad: never}|non-empty-list<int>, list<string>>',
        );
        $this->assertInstanceOf(ArrayMergeType::class, $direct);
        $this->assertNonEmptyListOf($resolver->resolve('int|string'), $direct->resolve());

        $template = $this->createTemplate(
            'listSpecialization',
            'T',
            new UnionType([
                $resolver->resolve('array{bad: never}'),
                $resolver->resolve('non-empty-list<int>'),
            ]),
        );
        $merge = $resolver->resolve(
            'array-merge<T, list<string>>',
            $this->createNameScope('listSpecialization', ['T' => $template]),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertNonEmptyListOf($resolver->resolve('int|string'), $merge->resolve());

        $specialized = $this->resolveTemplateTypes(
            $merge,
            $template,
            $resolver->resolve('non-empty-list<1>'),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $specialized);
        $this->assertNonEmptyListOf($resolver->resolve('1|string'), $specialized->resolve());
    }

    public function testWhollyImpossibleTemplateOperandMakesMultipleOperandMergeImpossible(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $direct = $resolver->resolve(
            'array-merge<array{head: bool}, array{bad: never}|non-empty-array<never, string>, array{tail: string}>',
        );
        $this->assertInstanceOf(ArrayMergeType::class, $direct);
        $this->assertInstanceOf(NeverType::class, $direct->resolve());

        $template = $this->createTemplate(
            'impossibleOperand',
            'T',
            new UnionType([
                $resolver->resolve('array{bad: never}'),
                $resolver->resolve('non-empty-array<never, string>'),
            ]),
        );
        $merge = $resolver->resolve(
            'array-merge<array{head: bool}, T, array{tail: string}>',
            $this->createNameScope('impossibleOperand', ['T' => $template]),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $actual = $merge->resolve();
        $this->assertInstanceOf(NeverType::class, $actual, sprintf(
            'Expected never, got %s',
            $actual->describe(VerbosityLevel::precise()),
        ));
    }

    public function testTemplateNestedInsideAnotherTemplateBoundCanStillBeSpecialized(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $inner = $this->createTemplate(
            'nestedTemplateGraph',
            'TInner',
            new UnionType([
                $resolver->resolve('array{bad: never}'),
                $resolver->resolve('array{valid: int}'),
            ]),
        );
        $outerBound = $resolver->resolve(
            'array{nested: TInner}',
            $this->createNameScope('nestedTemplateGraph', ['TInner' => $inner]),
        );
        $outer = $this->createTemplate('nestedTemplateGraph', 'TOuter', $outerBound);
        $merge = $resolver->resolve(
            'array-merge<TOuter, array{tail: string}>',
            $this->createNameScope('nestedTemplateGraph', [
                'TInner' => $inner,
                'TOuter' => $outer,
            ]),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertTypeEquals(
            $resolver->resolve('array{nested: array{valid: int}, tail: string}'),
            $merge->resolve(),
        );

        $specialized = $this->resolveTemplateTypes(
            $merge,
            $inner,
            $resolver->resolve('array{valid: 1}'),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $specialized);
        $this->assertTypeEquals(
            $resolver->resolve('array{nested: array{valid: 1}, tail: string}'),
            $specialized->resolve(),
        );
    }

    public function testNonArrayRemainderInNormalizedTemplateBoundIsStillRejected(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $template = $this->createTemplate(
            'invalidRemainder',
            'T',
            new UnionType([
                $resolver->resolve('array{bad: never}'),
                $resolver->resolve('int'),
            ]),
        );
        $merge = $resolver->resolve(
            'array-merge<array{head: bool}, T, array{tail: string}>',
            $this->createNameScope('invalidRemainder', ['T' => $template]),
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);

        $this->assertInstanceOf(ErrorType::class, $merge->resolve());
    }

    /**
     * @param array<string, TemplateType> $templates
     */
    private function createNameScope(string $functionName, array $templates): NameScope
    {
        return new NameScope(
            null,
            [],
            null,
            $functionName,
            new TemplateTypeMap($templates),
        );
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
        $result = (new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeHelper',
            'resolveTemplateTypes',
        ))->invoke(
            null,
            $type,
            new TemplateTypeMap([$from->getName() => $to]),
            TemplateTypeVarianceMap::createEmpty(),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(Type::class, $result);

        return $result;
    }

    private function assertTypeEquals(Type $expected, Type $actual): void
    {
        $this->assertTrue($expected->equals($actual), sprintf(
            'Expected %s, got %s',
            $expected->describe(VerbosityLevel::precise()),
            $actual->describe(VerbosityLevel::precise()),
        ));
    }

    private function assertNonEmptyListOf(Type $expectedValueType, Type $actual): void
    {
        $description = $actual->describe(VerbosityLevel::precise());
        $this->assertTrue($actual->isList()->yes(), sprintf(
            'Expected a list, got %s',
            $description,
        ));
        $this->assertTrue($actual->isIterableAtLeastOnce()->yes(), sprintf(
            'Expected a non-empty list, got %s',
            $description,
        ));
        $this->assertTrue($expectedValueType->equals($actual->getIterableValueType()), sprintf(
            'Expected list values %s, got %s',
            $expectedValueType->describe(VerbosityLevel::precise()),
            $actual->getIterableValueType()->describe(VerbosityLevel::precise()),
        ));
    }
}
