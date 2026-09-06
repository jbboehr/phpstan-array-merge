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
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use function array_flip;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * @phpstan-type RuntimeArray array<array-key, int|string>
 * @phpstan-type Operand array{string, non-empty-list<RuntimeArray>, bool}
 */
final class ArrayMergeNativeBehaviorTest extends PHPStanTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }

    /** @return array<string, Operand> */
    private static function operands(): array
    {
        // The boolean states a guarantee of the declared type, not of sampled values.
        return [
            'empty' => ['array{}', [[]], false],
            'numeric value collisions' => ["array{a: 7, b: '7', c: '07'}", [['a' => 7, 'b' => '7', 'c' => '07']], true],
            'string ab' => ["array{a: 'x', b: 'y'}", [['a' => 'x', 'b' => 'y']], true],
            'string ba' => ["array{b: 'x', a: 'y'}", [['b' => 'x', 'a' => 'y']], true],
            'duplicate values' => ["array{a: 'x', b: 'x'}", [['a' => 'x', 'b' => 'x']], true],
            'sparse integers' => ["array{-3: 'x', 7: 'y'}", [[-3 => 'x', 7 => 'y']], true],
            'numeric string' => ["array{'7': 'x'}", [[7 => 'x']], true],
            'leading zero string' => ["array{'07': 'y'}", [['07' => 'y']], true],
            'optional strings' => [
                "array{a?: 'x', b?: 'y'}",
                [[], ['a' => 'x'], ['b' => 'y'], ['a' => 'x', 'b' => 'y']],
                false,
            ],
            'optional mixed keys' => [
                "array{-3?: 'x', a: 'y', 7?: 'x'}",
                [['a' => 'y'], [-3 => 'x', 'a' => 'y'], ['a' => 'y', 7 => 'x'], [-3 => 'x', 'a' => 'y', 7 => 'x']],
                true,
            ],
            'nonempty union' => [
                "array{a: 'x'}|array{7: 'y'}",
                [['a' => 'x'], [7 => 'y']],
                true,
            ],
            'possibly empty union' => [
                "array{}|array{a: 'x', b: 'y'}|array{b: 'y', a: 'x'}",
                [[], ['a' => 'x', 'b' => 'y'], ['b' => 'y', 'a' => 'x']],
                false,
            ],
            'benevolent optional union' => [
                "__array_merge_benevolent<array{a?: 'x'}|array{a: 'x', b: 'y'}|array{b: 'y', a: 'x'}>",
                [[], ['a' => 'x'], ['a' => 'x', 'b' => 'y'], ['b' => 'y', 'a' => 'x']],
                false,
            ],
            'generic strings' => [
                "array<string, 'x'|'y'>",
                [[], ['a' => 'y'], ['b' => 'x', 'a' => 'x'], ['new' => 'y']],
                false,
            ],
            'generic integers' => [
                "array<int, 'x'|'y'>",
                [[], [9 => 'x'], [-3 => 'y', 9 => 'x']],
                false,
            ],
            'nonempty list' => [
                "non-empty-list<'x'|'y'>",
                [['x'], ['y', 'x']],
                true,
            ],
            'benevolent generic union' => [
                "__array_merge_benevolent<array<string, 'x'>|non-empty-array<int, 'y'>>",
                [[], ['a' => 'x'], [9 => 'y']],
                false,
            ],
        ];
    }

    /** @return iterable<string, array{non-empty-list<Operand>}> */
    public static function combinationProvider(): iterable
    {
        $operands = self::operands();
        foreach ($operands as $leftName => $left) {
            foreach ($operands as $rightName => $right) {
                yield $leftName . ' then ' . $rightName => [[$left, $right]];
            }
        }

        // A later operand can overwrite a key introduced by either earlier operand.
        $threeOperandNames = ['empty', 'string ab', 'string ba', 'optional strings', 'sparse integers'];
        foreach ($threeOperandNames as $first) {
            foreach ($threeOperandNames as $second) {
                foreach ($threeOperandNames as $third) {
                    yield $first . ' then ' . $second . ' then ' . $third => [[
                        $operands[$first], $operands[$second], $operands[$third],
                    ]];
                }
            }
        }
    }

    /** @param non-empty-list<Operand> $operands */
    #[DataProvider('combinationProvider')]
    public function testEveryDeclaredBranchContainsItsNativeOutcome(array $operands): void
    {
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $phpDoc = 'array-merge<' . implode(', ', array_map(static fn(array $operand): string => $operand[0], $operands)) . '>';
        $merge = $resolver->resolve($phpDoc);
        $this->assertInstanceOf(ArrayMergeType::class, $merge);
        $result = $merge->resolve();
        $this->assertTrue($result->isArray()->yes(), $phpDoc);

        $guaranteedNonempty = false;
        $integerKeysOnly = true;
        $outcomes = [[]];
        foreach ($operands as [$declaration, $branches, $nonempty]) {
            $operandType = $resolver->resolve('array-merge<' . $declaration . '>');
            $this->assertInstanceOf(ArrayMergeType::class, $operandType);
            $declaredType = $operandType->getTypes()[0];
            $integerKeysOnly = $integerKeysOnly && $declaredType->getIterableKeyType()->isInteger()->yes();
            $guaranteedNonempty = $guaranteedNonempty || $nonempty;
            $next = [];
            foreach ($branches as $branch) {
                $this->assertTrue(
                    $declaredType->isSuperTypeOf(ConstantTypeHelper::getTypeFromValue($branch))->yes(),
                    'Invalid fixture for ' . $declaration,
                );
                foreach ($outcomes as $prefix) {
                    $next[] = array_merge($prefix, $branch);
                }
            }
            $outcomes = $next;
        }

        $this->assertSame($guaranteedNonempty, $result->isIterableAtLeastOnce()->yes(), $phpDoc);
        if ($integerKeysOnly) {
            $this->assertTrue($result->isList()->yes(), $phpDoc);
        }

        $derived = [
            'merge' => $result,
            'keys' => $result->getKeysArray(),
            'values' => $result->getValuesArray(),
            'flip' => $result->flipArray(),
        ];
        $possibleKeys = [];
        foreach ($outcomes as $outcome) {
            foreach ($outcome as $key => $value) {
                $possibleKeys[$key] = true;
            }
        }

        foreach ($outcomes as $outcome) {
            $native = [
                'merge' => $outcome,
                'keys' => array_keys($outcome),
                'values' => array_values($outcome),
                'flip' => array_flip($outcome),
            ];
            foreach ($derived as $operation => $type) {
                $expected = ConstantTypeHelper::getTypeFromValue($native[$operation]);
                $message = sprintf(
                    '%s of %s inferred %s; native outcome %s',
                    $operation,
                    $phpDoc,
                    $type->describe(VerbosityLevel::precise()),
                    $expected->describe(VerbosityLevel::precise()),
                );
                $this->assertTrue($type->isSuperTypeOf($expected)->yes(), $message);
                $this->assertTrue(
                    $type->getArraySize()->isSuperTypeOf(new ConstantIntegerType(count($native[$operation])))->yes(),
                    $message,
                );
                if ($type->isList()->yes()) {
                    $this->assertTrue(array_is_list($native[$operation]), $message);
                }
            }

            foreach ($possibleKeys as $key => $_) {
                $keyType = ConstantTypeHelper::getTypeFromValue($key);
                if (array_key_exists($key, $outcome)) {
                    $this->assertFalse($result->hasOffsetValueType($keyType)->no(), $phpDoc);
                    $this->assertTrue(
                        $result->getOffsetValueType($keyType)->isSuperTypeOf(ConstantTypeHelper::getTypeFromValue($outcome[$key]))->yes(),
                        $phpDoc,
                    );
                } else {
                    $this->assertFalse($result->hasOffsetValueType($keyType)->yes(), $phpDoc);
                }
            }
        }
    }
}
