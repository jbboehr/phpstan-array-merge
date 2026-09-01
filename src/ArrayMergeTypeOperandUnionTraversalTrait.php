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
