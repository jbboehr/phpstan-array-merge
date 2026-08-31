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
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\NodeVisitor\CloningVisitor;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;

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
            if ($typeNode instanceof ArrayMergeTypeOperandUnionTypeNode) {
                return $this->resolveOperandUnionType($typeNode, $nameScope);
            }

            if (!$typeNode instanceof GenericTypeNode) {
                return null;
            }

            $typeName = $typeNode->type;

            if ($typeName->name === ArrayMergeTypeOperandBenevolentUnionType::PHPDOC_TYPE_NAME) {
                return $this->resolveOperandBenevolentUnionType($typeNode, $nameScope);
            }

            if ($typeName->name !== 'array-merge' || count($typeNode->genericTypes) <= 0) {
                return null;
            }

            $types = [];

            foreach ($typeNode->genericTypes as $genericTypeNode) {
                $type = $this->resolveOperandType($genericTypeNode, $nameScope);

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

    private function resolveOperandBenevolentUnionType(
        GenericTypeNode $typeNode,
        NameScope $nameScope,
    ): Type {
        if (count($typeNode->genericTypes) !== 1) {
            return new ErrorType();
        }

        $type = $this->resolveOperandType($typeNode->genericTypes[0], $nameScope);

        if (!($type instanceof UnionType) || $type instanceof TemplateType) {
            return $type;
        }

        return new ArrayMergeTypeOperandBenevolentUnionType($type->getTypes());
    }

    private function resolveOperandType(TypeNode $typeNode, NameScope $nameScope): Type
    {
        if ($typeNode instanceof UnionTypeNode) {
            $resolvedTypes = [];

            foreach ($typeNode->types as $innerTypeNode) {
                $resolvedTypes[] = $this->resolveOperandType($innerTypeNode, $nameScope);
            }

            return self::createOperandUnion($resolvedTypes);
        }

        $typeNodes = (new NodeTraverser([
            new CloningVisitor(),
            new class extends AbstractNodeVisitor {
                public function leaveNode(Node $node): ?Node
                {
                    if (
                        !($node instanceof UnionTypeNode)
                        || $node instanceof ArrayMergeTypeOperandUnionTypeNode
                    ) {
                        return null;
                    }

                    return new ArrayMergeTypeOperandUnionTypeNode($node->types);
                }
            },
        ]))->traverse([$typeNode]);

        $typeNode = $typeNodes[0];

        if (!($typeNode instanceof TypeNode)) {
            throw new \LogicException('Operand type-node traversal returned a non-type node');
        }

        return $this->typeNodeResolver->resolve($typeNode, $nameScope);
    }

    private function resolveOperandUnionType(
        ArrayMergeTypeOperandUnionTypeNode $typeNode,
        NameScope $nameScope,
    ): Type {
        $resolvedTypes = [];
        $survivingTypeNodes = [];

        foreach ($typeNode->types as $innerTypeNode) {
            $resolvedType = ArrayMergeType::normalizeUninhabitedArrays(
                $this->typeNodeResolver->resolve($innerTypeNode, $nameScope),
            );

            if ($resolvedType instanceof NeverType) {
                continue;
            }

            $resolvedTypes[] = $resolvedType;
            $survivingTypeNodes[] = $innerTypeNode;
        }

        if ([] === $resolvedTypes) {
            return new NeverType();
        }

        if (count($resolvedTypes) === 1) {
            return $resolvedTypes[0];
        }

        $containsTemplateType = false;

        foreach ($resolvedTypes as $resolvedType) {
            if (TypeUtils::containsTemplateType($resolvedType)) {
                $containsTemplateType = true;
                break;
            }
        }

        if (!$containsTemplateType) {
            return $this->typeNodeResolver->resolve(
                new UnionTypeNode($survivingTypeNodes),
                $nameScope,
            );
        }

        return self::createOperandUnion($resolvedTypes);
    }

    /**
     * @param list<Type> $resolvedTypes
     */
    private static function createOperandUnion(array $resolvedTypes): Type
    {
        if ([] === $resolvedTypes) {
            return new ErrorType();
        }

        $hasCoveringBenevolentUnion = self::hasCoveringBenevolentUnion($resolvedTypes);

        // Keep union branches separate so required never offsets remain visible,
        // but flatten non-template unions because UnionType rejects those when nested.
        $types = [];

        foreach ($resolvedTypes as $resolvedType) {
            if ($resolvedType instanceof UnionType && !($resolvedType instanceof TemplateType)) {
                foreach ($resolvedType->getTypes() as $nestedType) {
                    $types[] = $nestedType;
                }
            } else {
                $types[] = $resolvedType;
            }
        }

        if ([] === $types) {
            return new ErrorType();
        }

        return $hasCoveringBenevolentUnion
            ? new ArrayMergeTypeOperandBenevolentUnionType($types)
            : new ArrayMergeTypeOperandUnionType($types, $resolvedTypes);
    }

    /**
     * @param list<Type> $types
     */
    private static function hasCoveringBenevolentUnion(array $types): bool
    {
        foreach ($types as $candidate) {
            if (!($candidate instanceof BenevolentUnionType) || $candidate instanceof TemplateType) {
                continue;
            }

            foreach ($types as $type) {
                if ($type === $candidate || $candidate->isSuperTypeOf($type)->yes()) {
                    continue;
                }

                continue 2;
            }

            return true;
        }

        return false;
    }
}
