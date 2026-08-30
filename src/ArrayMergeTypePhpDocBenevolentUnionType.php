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

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;

/**
 * Adds PHPStan's benevolent marker while a containing type is serialized.
 *
 * @internal
 */
final class ArrayMergeTypePhpDocBenevolentUnionType extends BenevolentUnionType
{
    public static function toPhpDocNodeForType(Type $type): TypeNode
    {
        $type = TypeTraverser::map(
            $type,
            static function (Type $type, callable $traverse): Type {
                if (
                    $type instanceof self
                    || $type instanceof ArrayMergeTypeOperandBenevolentUnionType
                    || $type instanceof TemplateType
                ) {
                    return $type;
                }

                $type = $traverse($type);

                if (!($type instanceof BenevolentUnionType) || $type instanceof TemplateType) {
                    return $type;
                }

                return new self($type->getTypes());
            },
        );

        return $type->toPhpDocNode();
    }

    public function toPhpDocNode(): TypeNode
    {
        $type = new BenevolentUnionType($this->getTypes());

        if ($type->equals(new BenevolentUnionType([new IntegerType(), new StringType()]))) {
            return new IdentifierTypeNode('array-key');
        }

        return new GenericTypeNode(
            new IdentifierTypeNode('__benevolent'),
            [parent::toPhpDocNode()],
        );
    }
}
