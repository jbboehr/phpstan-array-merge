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

use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\Type;

final class ArrayMergeTypeNodeResolverExtension implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
    private TypeNodeResolver $typeNodeResolver;

    public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
    {
        $this->typeNodeResolver = $typeNodeResolver;
    }

    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        try {
            if (!$typeNode instanceof GenericTypeNode) {
                return null;
            }

            $typeName = $typeNode->type;

            if ($typeName->name !== 'array-merge' || count($typeNode->genericTypes) <= 0) {
                return null;
            }

            $types = [];

            foreach ($typeNode->genericTypes as $genericTypeNode) {
                $type = $this->typeNodeResolver->resolve($genericTypeNode, $nameScope);

                if ($type instanceof ArrayMergeType) {
                    foreach ($type->getTypes() as $childType) {
                        $types[] = $childType;
                    }
                } else {
                    $types[] = $type;
                }
            }

            return new ArrayMergeType($types);
        } catch (\Throwable $e) {
            ShouldNotHappenException::rethrow($e);
        }
    }
}
