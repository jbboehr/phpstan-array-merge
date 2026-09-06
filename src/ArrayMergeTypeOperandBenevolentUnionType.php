<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXV John Boehr & contributors
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * and the Romic Exception along with this program. See LICENSE.md and
 * docs/LICENSE_EXCEPTION.md for the complete terms.
 */
declare(strict_types=1);

namespace jbboehr\PHPStan\ArrayMerge;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;

/**
 * Keeps benevolent operand alternatives grouped until templates are resolved.
 *
 * @internal
 */
final class ArrayMergeTypeOperandBenevolentUnionType extends BenevolentUnionType
{
    use ArrayMergeTypeOperandUnionTraversalTrait;

    public const PHPDOC_TYPE_NAME = '__array_merge_benevolent';

    /** @return list<Type> */
    protected function getOperandTypes(): array
    {
        return $this->getTypes();
    }

    /** @param list<Type> $types */
    protected function recombineTypes(array $types): Type
    {
        if ([] === $types) {
            return $this;
        }

        $types = array_map(
            static fn(Type $type): Type => ArrayMergeType::normalizeUninhabitedArrays($type),
            $types,
        );

        $flattenedTypes = self::flattenOrdinaryUnions($types);

        foreach ($flattenedTypes as $type) {
            if (TypeUtils::containsTemplateType($type)) {
                return new self($flattenedTypes);
            }
        }

        $result = TypeCombinator::union(...$types);

        return $result instanceof UnionType
            ? new BenevolentUnionType($result->getTypes())
            : $result;
    }

    public function toPhpDocNode(): TypeNode
    {
        return new GenericTypeNode(
            new IdentifierTypeNode(self::PHPDOC_TYPE_NAME),
            [new UnionTypeNode(array_map(
                static fn(Type $type): TypeNode =>
                    ArrayMergeTypePhpDocBenevolentUnionType::toPhpDocNodeForType($type),
                $this->getSortedTypes(),
            ))],
        );
    }
}
