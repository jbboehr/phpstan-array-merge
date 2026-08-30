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

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;

/**
 * Keeps operand alternatives separate while PHPStan replaces template types.
 *
 * UnionType::traverse() normally recombines changed members with TypeCombinator,
 * which can discard a TemplateUnionType that must remain unresolved.
 *
 * @internal
 */
final class ArrayMergeTypeOperandUnionType extends UnionType
{
    /** @var non-empty-list<Type> */
    private array $sourceTypes;

    /**
     * @param non-empty-list<Type> $types
     * @param non-empty-list<Type>|null $sourceTypes
     */
    public function __construct(array $types, ?array $sourceTypes = null)
    {
        parent::__construct($types);

        $this->sourceTypes = $sourceTypes ?? $types;
    }

    public function equals(Type $type): bool
    {
        if (!($type instanceof self) || !parent::equals($type)) {
            return false;
        }

        $otherSourceTypes = $type->sourceTypes;

        foreach ($this->sourceTypes as $sourceType) {
            foreach ($otherSourceTypes as $i => $otherSourceType) {
                if (!$sourceType->equals($otherSourceType)) {
                    continue;
                }

                unset($otherSourceTypes[$i]);
                continue 2;
            }

            return false;
        }

        return [] === $otherSourceTypes;
    }

    public function traverse(callable $cb): Type
    {
        $types = [];
        $replace = false;

        foreach ($this->sourceTypes as $type) {
            $newType = $cb($type);
            $types[] = $newType;
            if ($newType !== $type) {
                $replace = true;
            }
        }

        if (!$replace) {
            return $this;
        }

        $typesWithoutNever = array_values(array_filter(
            $types,
            static fn(Type $type): bool => !($type instanceof NeverType),
        ));
        $flattenedTypes = self::flattenOrdinaryUnions($types);

        if ([] === $flattenedTypes) {
            return $types[0];
        }

        foreach ($flattenedTypes as $type) {
            if (TypeUtils::containsTemplateType($type)) {
                $benevolentType = self::getCoveringBenevolentUnion($typesWithoutNever);
                if (null !== $benevolentType) {
                    return new ArrayMergeTypeOperandBenevolentUnionType(
                        $flattenedTypes,
                    );
                }

                return new self($flattenedTypes, $types);
            }
        }

        if ([] === $typesWithoutNever) {
            return $types[0];
        }

        if (count($typesWithoutNever) === 1) {
            return $typesWithoutNever[0];
        }

        return TypeCombinator::union(...$typesWithoutNever);
    }

    public function toPhpDocNode(): TypeNode
    {
        return new UnionTypeNode(array_map(
            static fn(Type $type): TypeNode =>
                ArrayMergeTypePhpDocBenevolentUnionType::toPhpDocNodeForType($type),
            $this->sourceTypes,
        ));
    }

    /**
     * @param list<Type> $types
     */
    private static function getCoveringBenevolentUnion(array $types): ?BenevolentUnionType
    {
        foreach ($types as $candidate) {
            if (
                !$candidate instanceof BenevolentUnionType
                || $candidate instanceof TemplateType
            ) {
                continue;
            }

            foreach ($types as $type) {
                if ($type === $candidate || $candidate->isSuperTypeOf($type)->yes()) {
                    continue;
                }

                continue 2;
            }

            return $candidate;
        }

        return null;
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
