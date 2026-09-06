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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * and the Romic Exception along with this program. See LICENSE.md and
 * docs/LICENSE_EXCEPTION.md for the complete terms.
 */
declare(strict_types=1);

namespace jbboehr\PHPStan\ArrayMerge;

use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;

/**
 * @internal
 */
trait ArrayMergeTypeOperandUnionTraversalTrait
{
    /** @return list<Type> */
    abstract protected function getOperandTypes(): array;

    /** @param list<Type> $types */
    abstract protected function recombineTypes(array $types): Type;

    public function traverse(callable $cb): Type
    {
        $types = [];
        $replace = false;

        foreach ($this->getOperandTypes() as $type) {
            $newType = $cb($type);
            $types[] = $newType;
            if ($newType !== $type) {
                $replace = true;
            }
        }

        return $replace ? $this->recombineTypes($types) : $this;
    }

    public function traverseSimultaneously(Type $right, callable $cb): Type
    {
        // PHPStan uses this traversal for too-wide diagnostics. Skipping an unresolved
        // right side is conservative; traversing it can discard later specialization.
        if (TypeUtils::containsTemplateType($right)) {
            return $this;
        }

        $rightTypes = TypeUtils::flattenTypes($right);
        $types = [];
        $replace = false;

        foreach ($this->getOperandTypes() as $type) {
            $candidates = [];

            foreach ($rightTypes as $i => $rightType) {
                if (!$type->isSuperTypeOf($rightType)->yes()) {
                    continue;
                }

                $candidates[] = $rightType;
                unset($rightTypes[$i]);
            }

            if ([] === $candidates) {
                $types[] = $type;
                continue;
            }

            $newType = $cb($type, TypeCombinator::union(...$candidates));
            $types[] = $newType;
            if ($newType !== $type) {
                $replace = true;
            }
        }

        return $replace ? $this->recombineTypes($types) : $this;
    }

    /**
     * @param list<Type> $types
     * @return list<Type>
     */
    private static function flattenOrdinaryUnions(array $types): array
    {
        $flattenedTypes = [];

        foreach ($types as $type) {
            if ($type instanceof UnionType && !($type instanceof TemplateType)) {
                foreach ($type->getTypes() as $innerType) {
                    $flattenedTypes[] = $innerType;
                }
            } else {
                $flattenedTypes[] = $type;
            }
        }

        return $flattenedTypes;
    }
}
