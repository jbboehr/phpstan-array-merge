<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXVI John Boehr & contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-only WITH romic-exception
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License version 3,
 * as published by the Free Software Foundation, together with the Romic
 * Exception (an additional permission under section 7 of that license).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * and the Romic Exception along with this program. See LICENSE.md and
 * docs/LICENSE_EXCEPTION.md for the complete terms.
 */
declare(strict_types=1);

namespace jbboehr\PHPStan\ArrayMerge\Tests;

use jbboehr\PHPStan\ArrayMerge\ArrayMergeType;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;

final class ArrayMergeContractTest extends TemplateTypeTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function resultProvider(): iterable
    {
        yield 'one empty operand' => ['array-merge<array{}>', 'array{}'];
        yield 'several empty operands' => ['array-merge<array{}, array{}, array{}>', 'array{}'];
        yield 'string shape identity' => ['array-merge<array{a: int, b: string}>', 'array{a: int, b: string}'];
        yield 'empty operands preserve string order' => [
            'array-merge<array{}, array{b: 2, a: 1}, array{}>', 'array{b: 2, a: 1}',
        ];
        yield 'rightmost value wins without moving the key' => [
            'array-merge<array{a: 1, b: 2}, array{b: 3, a: 4}>', 'array{a: 4, b: 3}',
        ];
        yield 'overwrite across three operands' => [
            'array-merge<array{a: 1}, array{b: 2, a: 3}, array{c: 4, a: 5}>', 'array{a: 5, b: 2, c: 4}',
        ];
        yield 'null is an overwrite value' => ['array-merge<array{a: 1}, array{a: null}>', 'array{a: null}'];
        yield 'false is an overwrite value' => ['array-merge<array{a: 1}, array{a: false}>', 'array{a: false}'];
        yield 'nested arrays are replaced rather than recursively merged' => [
            'array-merge<array{payload: array{old: 1}}, array{payload: array{new: 2}}>', 'array{payload: array{new: 2}}',
        ];
        yield 'empty nested array replaces a nonempty value' => [
            'array-merge<array{payload: array{old: 1}}, array{payload: array{}}>', 'array{payload: array{}}',
        ];
        yield 'scalar and container values stay distinct' => [
            'array-merge<array{a: true, b: null, c: 1.5}, array{d: array{0: false}}>',
            'array{a: true, b: null, c: 1.5, d: array{0: false}}',
        ];
        yield 'negative and sparse integer keys are reindexed' => [
            "array-merge<array{-9: 'a', 14: 'b'}, array{14: 'c'}>", "array{0: 'a', 1: 'b', 2: 'c'}",
        ];
        yield 'integer key collisions append' => [
            "array-merge<array{0: 'a'}, array{0: 'b'}, array{0: 'c'}>", "array{0: 'a', 1: 'b', 2: 'c'}",
        ];
        yield 'canonical numeric strings append' => [
            "array-merge<array{'7': 'a'}, array{'7': 'b'}>", "array{0: 'a', 1: 'b'}",
        ];
        yield 'noncanonical numeric strings remain separate string keys' => [
            "array-merge<array{'07': 1, '+7': 2, '7.0': 3, '7e0': 4, '-0': 5}, array{'07': 6}>",
            "array{'07': 6, '+7': 2, '7.0': 3, '7e0': 4, '-0': 5}",
        ];
        yield 'string keys are case sensitive and may be empty' => [
            "array-merge<array{'': 1, a: 2}, array{A: 3, '': 4}>", "array{'': 4, a: 2, A: 3}",
        ];
        yield 'mixed keys retain interleaving while reindexing integers' => [
            "array-merge<array{9: 'x', a: 'y'}, array{3: 'z', a: 'last', b: 'end'}>",
            "array{0: 'x', a: 'last', 1: 'z', b: 'end'}",
        ];
        yield 'required write overrides an optional earlier write' => [
            'array-merge<array{a?: 1}, array{a: 2}>', 'array{a: 2}',
        ];
        yield 'optional later write preserves the earlier alternative' => [
            'array-merge<array{a: 1}, array{a?: 2}>', 'array{a: 1|2}',
        ];
        yield 'disjoint optional fields stay optional' => [
            'array-merge<array{a?: 1}, array{b?: 2}>', 'array{a?: 1, b?: 2}',
        ];
        yield 'optional impossible field disappears' => [
            'array-merge<array{absent?: never, present: int}>', 'array{present: int}',
        ];
        yield 'impossible union branch contributes no fields' => [
            'array-merge<array{bad: never}|array{valid: int}>', 'array{valid: int}',
        ];
        yield 'never alternative is neutral' => ['array-merge<never|array{valid: int}>', 'array{valid: int}'];
        yield 'empty generic array is empty' => ['array-merge<array<never, never>>', 'array{}'];
        yield 'generic string arrays retain the combined value domain' => [
            'array-merge<array<string, int>, array<string, bool>>', 'array<string, bool|int>',
        ];
        yield 'mixed keys cannot be assumed to form a list' => [
            'array-merge<array<array-key, int>, array<string, bool>>', 'array<array-key, bool|int>',
        ];
        yield 'nested sibling merges preserve last-write order' => [
            'array-merge<array-merge<array{value: 1}, array{value: 2}>, array-merge<array{value: 3}, array{tail: true}>>',
            'array{value: 3, tail: true}',
        ];
        yield 'nested numeric merges do not overwrite repeated numeric keys' => [
            'array-merge<array{7: 1}, array-merge<array{7: 2}, array{7: 3}>>', 'array{0: 1, 1: 2, 2: 3}',
        ];
        yield 'callable signature unions keep their meaning' => [
            'array-merge<array{callback: callable(int|string): (bool|null)}>',
            'array{callback: callable(int|string): (bool|null)}',
        ];
        yield 'nested container unions keep their meaning' => [
            'array-merge<array{payload: list<array{value: int|string}|null>}>',
            'array{payload: list<array{value: int|string}|null>}',
        ];
    }

    #[DataProvider('resultProvider')]
    public function testExpectedResultBeforeAndAfterPhpDocRoundTrip(string $input, string $expected): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($input);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $expectedType = $resolver->resolve($expected);

        // Resolving first also exercises serialization after a cached preview.
        $this->assertResultEquals($expectedType, $merge->resolve(), $input);
        $printed = (new Printer())->print($merge->toPhpDocNode());
        $roundTrip = $resolver->resolve($printed);
        $this->assertInstanceOf(ArrayMergeType::class, $roundTrip);
        $this->assertResultEquals($expectedType, $roundTrip->resolve(), $printed);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function listResultProvider(): iterable
    {
        yield 'generic integer arrays become lists' => ['array-merge<array<int, string>>', 'string', false];
        yield 'nonempty generic integer arrays become nonempty lists' => [
            'array-merge<non-empty-array<int, string>>', 'string', true,
        ];
        yield 'merging lists combines their value domains' => [
            'array-merge<list<int>, list<string>>', 'int|string', false,
        ];
        yield 'one nonempty list guarantees a nonempty merge' => [
            'array-merge<list<int>, non-empty-list<string>>', 'int|string', true,
        ];
    }

    #[DataProvider('listResultProvider')]
    public function testListSemanticsBeforeAndAfterPhpDocRoundTrip(string $input, string $valueType, bool $nonempty): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($input);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $direct = $merge->resolve();
        $roundTrip = $resolver->resolve((new Printer())->print($merge->toPhpDocNode()));
        $this->assertInstanceOf(ArrayMergeType::class, $roundTrip);

        // Older PHPStan parsers can erase list annotations. Assert the semantic
        // contract directly so the expected type cannot silently lose this guarantee.
        foreach ([$direct, $roundTrip->resolve()] as $result) {
            $this->assertTrue($result->isList()->yes(), $input);
            $this->assertSame($nonempty, $result->isIterableAtLeastOnce()->yes(), $input);
            $this->assertSame(
                !$nonempty,
                $result->isSuperTypeOf(ConstantArrayTypeBuilder::createEmpty()->getArray())->yes(),
                $input,
            );
            $this->assertTrue($resolver->resolve($valueType)->equals($result->getIterableValueType()), $input);
            $this->assertTrue($resolver->resolve('int<0, max>')->equals($result->getIterableKeyType()), $input);
            $this->assertTrue($result->getKeysArray()->isList()->yes(), $input);
            $this->assertTrue($result->getValuesArray()->isList()->yes(), $input);
        }
    }

    /** @return iterable<string, array{string, class-string<Type>}> */
    public static function invalidOrImpossibleProvider(): iterable
    {
        yield 'scalar' => ['array-merge<int>', ErrorType::class];
        yield 'object' => ['array-merge<stdClass>', ErrorType::class];
        yield 'mixed' => ['array-merge<mixed>', ErrorType::class];
        yield 'iterable may be an object' => ['array-merge<iterable<string, int>>', ErrorType::class];
        yield 'nullable shape' => ['array-merge<?array{a: int}>', ErrorType::class];
        yield 'union with false' => ['array-merge<array{a: int}|false>', ErrorType::class];
        yield 'invalid first operand' => ['array-merge<string, array{}>', ErrorType::class];
        yield 'invalid last operand' => ['array-merge<array{}, string>', ErrorType::class];
        yield 'invalid overrides impossible first operand' => ['array-merge<never, int>', ErrorType::class];
        yield 'invalid overrides impossible last operand' => ['array-merge<int, never>', ErrorType::class];
        yield 'invalid nested operand' => ['array-merge<array-merge<array{}, int>, array{}>', ErrorType::class];
        yield 'never operand' => ['array-merge<never, array{a: int}>', NeverType::class];
        yield 'required impossible field' => ['array-merge<array{a: never}>', NeverType::class];
        yield 'all union alternatives impossible' => ['array-merge<array{a: never}|array{b: never}>', NeverType::class];
        yield 'nonempty array with impossible value' => ['array-merge<non-empty-array<string, never>>', NeverType::class];
        yield 'required nested impossible field' => ['array-merge<array{a: array{b: never}}>', NeverType::class];
    }

    /** @param class-string<Type> $expectedClass */
    #[DataProvider('invalidOrImpossibleProvider')]
    public function testInvalidAndImpossibleInputsHaveDistinctOutcomes(string $input, string $expectedClass): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve($input);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $this->assertInstanceOf($expectedClass, $merge->resolve(), $input);
        $roundTrip = $resolver->resolve((new Printer())->print($merge->toPhpDocNode()));
        $this->assertInstanceOf(ArrayMergeType::class, $roundTrip);
        $this->assertInstanceOf($expectedClass, $roundTrip->resolve(), $input);
    }

    public function testImportedClassNameSurvivesNestedUnionParsing(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $scope = new NameScope('Project', ['alias' => 'Vendor\\Widget']);
        $merge = $resolver->resolve('array-merge<array{value: Alias|null}>', $scope);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $this->assertResultEquals(
            $resolver->resolve('array{value: \\Vendor\\Widget|null}'),
            $merge->resolve(),
            'Imported class inside an operand union',
        );
    }

    public function testMergeStoredInsideAnElementDoesNotBecomeAnOuterOperand(): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $merge = $resolver->resolve(
            'array-merge<array{payload: array-merge<array{left: int}, array{right: string}>}, array{done: bool}>',
        );
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();
        $this->assertResultEquals($resolver->resolve("array{0: 'payload', 1: 'done'}"), $result->getKeysArray(), 'Outer keys');
        $payload = $result->getOffsetValueType(new ConstantStringType('payload'));
        if ($payload instanceof LateResolvableType) {
            $payload = $payload->resolve();
        }
        $this->assertResultEquals($resolver->resolve('array{left: int, right: string}'), $payload, 'Nested payload');
    }

    /** @return iterable<string, array{bool}> */
    public static function specializationOrderProvider(): iterable
    {
        yield 'specialize first operand first' => [true];
        yield 'specialize last operand first' => [false];
    }

    #[DataProvider('specializationOrderProvider')]
    public function testPreviewAndRoundTripDoNotFreezePartiallySpecializedOperands(bool $firstOperandFirst): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $templateT = $this->createTemplate('mergeContract', 'T', $resolver->resolve('array{value: int}'));
        $templateU = $this->createTemplate('mergeContract', 'U', $resolver->resolve('array{value: string}'));
        $scope = new NameScope(null, [], null, 'mergeContract', new TemplateTypeMap(['T' => $templateT, 'U' => $templateU]));
        $original = $resolver->resolve('array-merge<T, U, array{tail: true}>', $scope);
        $this->assertInstanceOf(ArrayMergeType::class, $original);
        $this->assertFalse($original->isResolvable());

        $bindings = $firstOperandFirst
            ? [[$templateT, 'array{value: 1}'], [$templateU, "array{value: 'last'}"]]
            : [[$templateU, "array{value: 'last'}"], [$templateT, 'array{value: 1}']];
        $partial = $this->resolveTemplateTypes($original, $bindings[0][0], $resolver->resolve($bindings[0][1]));
        $this->assertInstanceOf(ArrayMergeType::class, $partial);
        $this->assertFalse($partial->isResolvable());
        $this->assertResultEquals(
            $resolver->resolve($firstOperandFirst ? 'array{value: string, tail: true}' : "array{value: 'last', tail: true}"),
            $partial->resolve(),
            'Preview uses the remaining bound',
        );

        $roundTrip = $resolver->resolve((new Printer())->print($partial->toPhpDocNode()), $scope);
        $this->assertInstanceOf(ArrayMergeType::class, $roundTrip);
        $this->assertFalse($roundTrip->isResolvable());
        foreach ([$partial, $roundTrip] as $path) {
            $final = $this->resolveTemplateTypes($path, $bindings[1][0], $resolver->resolve($bindings[1][1]));
            $this->assertInstanceOf(ArrayMergeType::class, $final);
            $this->assertTrue($final->isResolvable());
            $this->assertResultEquals($resolver->resolve("array{value: 'last', tail: true}"), $final->resolve(), 'Final result');
        }

        $this->assertFalse($original->isResolvable());
        $this->assertResultEquals($resolver->resolve('array{value: string, tail: true}'), $original->resolve(), 'Original bounds');
    }

    private function assertResultEquals(Type $expected, Type $actual, string $context): void
    {
        $message = $context . ': expected ' . $expected->describe(VerbosityLevel::precise())
            . ', got ' . $actual->describe(VerbosityLevel::precise());
        $this->assertTrue($expected->equals($actual), $message);
        $this->assertTrue($expected->getKeysArray()->equals($actual->getKeysArray()), 'Key order: ' . $message);
        $this->assertTrue($expected->getValuesArray()->equals($actual->getValuesArray()), 'Value order: ' . $message);
    }
}
