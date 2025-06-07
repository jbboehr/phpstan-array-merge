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
use PHPStan\Type\Generic\TemplateType;
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
        if (!$typeNode instanceof GenericTypeNode) {
            // returning null means this extension is not interested in this node
            return null;
        }

        $typeName = $typeNode->type;
        if ($typeName->name !== 'array-merge') {
            return null;
        }

        if (count($typeNode->genericTypes) <= 1) {
            return null;
        }

        $hasTemplateType = false;

        $types = array_values(array_map(
            function (TypeNode $typeNode) use ($nameScope, &$hasTemplateType) {
                $type = $this->typeNodeResolver->resolve($typeNode, $nameScope);

                if ($type instanceof TemplateType) {
                    $hasTemplateType = true;
                }

                return $type;
            },
            $typeNode->genericTypes,
        ));

        $arrayMergeType = new ArrayMergeType($types);

//        if (!$hasTemplateType) {
            return $arrayMergeType;
//        }
//
//        foreach ($types as $index => $type) {
//            if ($type instanceof TemplateType) {
//                $templateType = new ArrayMergeTemplateType(
//                    $nameScope->getTemplateTypeScope(),
//                    new TemplateTypeParameterStrategy(),
//                    TemplateTypeVariance::createInvariant(), // @TODO
//                    $type->getName(),
//                    $arrayMergeType
//                );
//
//                dd($templateType->describe(VerbosityLevel::typeOnly()));
//
//                // $types[$index] =
//            }
//        }
//
//        return new ArrayMergeType($types);

//        dd($typeNode->variances);

//        return
//
//        $strategy ??= ;
//        dd($types);

//        return new ArrayMergeType();

//        $arguments = $typeNode->genericTypes;
//
//
//        foreach ($arguments as $argument) {
//            $type = $this->typeNodeResolver->resolve($argument, $nameScope);
//
//            dd($type);
////            $constantArrays = $type->getConstantArrays();
////            if (count($constantArrays) === 0) {
////                return null;
////            }
////
////            dd($constantArrays);
//        }
//
//        dd($arguments);

//        if (count($arguments) !== 2) {
//            return null;
//        }
//
//        $arrayType = $this->typeNodeResolver->resolve($arguments[0], $nameScope);
//        $keysType = $this->typeNodeResolver->resolve($arguments[1], $nameScope);
//
//        $constantArrays = $arrayType->getConstantArrays();
//        if (count($constantArrays) === 0) {
//            return null;
//        }
//
//        $newTypes = [];
//        foreach ($constantArrays as $constantArray) {
//            $newTypeBuilder = ConstantArrayTypeBuilder::createEmpty();
//            foreach ($constantArray->getKeyTypes() as $i => $keyType) {
//                if (!$keysType->isSuperTypeOf($keyType)->yes()) {
//                    // eliminate keys that aren't in the Pick type
//                    continue;
//                }
//
//                $valueType = $constantArray->getValueTypes()[$i];
//                $newTypeBuilder->setOffsetValueType(
//                    $keyType,
//                    $valueType,
//                    $constantArray->isOptionalKey($i),
//                );
//            }
//
//            $newTypes[] = $newTypeBuilder->getArray();
//        }
//
//        return TypeCombinator::union(...$newTypes);

//        return null;
    }
}
