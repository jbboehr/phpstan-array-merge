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
