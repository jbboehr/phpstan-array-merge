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

namespace jbboehr\PHPStan\ArrayMerge;

use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;

/**
 * Keeps benevolent operand alternatives grouped until templates are resolved.
 *
 * @internal
 */
final class ArrayMergeTypeOperandBenevolentUnionType extends BenevolentUnionType
{
    public function traverse(callable $cb): Type
    {
        $types = [];
        $replace = false;

        foreach ($this->getTypes() as $type) {
            $newType = $cb($type);
            $types[] = $newType;
            if ($newType !== $type) {
                $replace = true;
            }
        }

        if (!$replace) {
            return $this;
        }

        $flattenedTypes = self::flattenOrdinaryUnions($types);

        foreach ($flattenedTypes as $type) {
            if ($type instanceof UnionType && $type instanceof TemplateType) {
                return new self($flattenedTypes);
            }
        }

        $result = TypeCombinator::union(...$types);

        return $result instanceof UnionType
            ? new BenevolentUnionType($result->getTypes())
            : $result;
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
