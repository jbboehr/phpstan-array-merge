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
use PHPStan\Type\ArrayType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Traits\LateResolvableTypeTrait;
use PHPStan\Type\Traits\NonGeneralizableTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;
use function sprintf;

/**
 * @phpstan-ignore-next-line phpstanApi.interface
 */
class ArrayMergeType implements CompoundType, LateResolvableType
{
    /** @phpstan-ignore-next-line phpstanApi.trait */
    use LateResolvableTypeTrait;

    /** @phpstan-ignore-next-line phpstanApi.trait */
    use NonGeneralizableTypeTrait;

    /**
     * @param non-empty-list<Type> $types
     */
    public function __construct(
        private array $types,
    ) {
    }

    /**
     * @return non-empty-list<Type>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    public function getReferencedClasses(): array
    {
        $rv = [];

        foreach ($this->types as $type) {
            $rv = array_merge($rv, $type->getReferencedClasses());
        }

        return $rv;
    }

    public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
    {
        $rv = [];

        foreach ($this->types as $type) {
            $rv = array_merge($rv, $type->getReferencedTemplateTypes($positionVariance));
        }

        return $rv;
    }

    public function equals(Type $type): bool
    {
        if (!($type instanceof self)) {
            return false;
        }

        if (count($type->types) !== count($this->types)) {
            return false;
        }

        for ($i = 0, $l = count($this->types); $i < $l; $i++) {
            if (!$type->types[$i]->equals($this->types[$i])) {
                return false;
            }
        }

        return true;
    }

    public function describe(VerbosityLevel $level): string
    {
        return sprintf(
            'array-merge<%s>',
            join(', ', array_map(static function (Type $type) use ($level): string {
                return $type->describe($level);
            }, $this->types)),
        );
    }

    public function isResolvable(): bool
    {
        foreach ($this->types as $type) {
            if (TypeUtils::containsTemplateType($type)) {
                return false;
            }
        }

        return true;
    }

    protected function getResult(): Type
    {
        $nConstantLists = 0;
        $nConstantArrays = 0;
        $nOtherArrays = 0;

        foreach ($this->types as $type) {
            if ($type->isConstantArray()->yes()) {
                if ($type->isList()->yes()) {
                    $nConstantLists++;
                }
                $nConstantArrays++;
            } elseif ($type->isArray()->yes()) {
                $nOtherArrays++;
            }
        }

        if ($nConstantLists === count($this->types)) {
            $builder = ConstantArrayTypeBuilder::createEmpty();
            $index = 0;

            foreach ($this->types as $type) {
                /** @TODO don't handle more than one atm */
                if (count($type->getConstantArrays()) !== 1) {
                    return new MixedType();
                }
                $constantArrayType = $type->getConstantArrays()[0];
                $valueTypes = $constantArrayType->getValueTypes();

                foreach ($valueTypes as $valueType) {
                    $builder->setOffsetValueType(new ConstantIntegerType($index++), $valueType);
                }
            }

            return $builder->getArray();
        }

        if ($nConstantArrays === count($this->types)) {
            $builder = ConstantArrayTypeBuilder::createEmpty();
            foreach ($this->types as $type) {
                /** @TODO don't handle more than one atm */
                if (count($type->getConstantArrays()) !== 1) {
                    return new MixedType();
                }

                $constantArrayType = $type->getConstantArrays()[0];

                $keyTypes = $constantArrayType->getKeyTypes();
                $valueTypes = $constantArrayType->getValueTypes();
                $l = count($keyTypes);

                if ($l !== count($valueTypes)) {
                    return new MixedType();
                }

                for ($i = 0; $i < $l; $i++) {
                    $builder->setOffsetValueType($keyTypes[$i], $valueTypes[$i], $constantArrayType->isOptionalKey($i));
                }
            }

            return $builder->getArray();
        }

        if ($nOtherArrays === count($this->types)) {
            $combinedKeyType = null;
            $combinedItemType = null;

            foreach ($this->types as $type) {
                /** @TODO don't handle more than one atm */
                if (count($type->getArrays()) !== 1) {
                    return new MixedType();
                }

                $arrayType = $type->getArrays()[0];
                $keyType = $arrayType->getKeyType();
                $itemType = $arrayType->getItemType();

                if (null === $combinedKeyType) {
                    $combinedKeyType = $keyType;
                    $combinedItemType = $itemType;
                } else {
                    $combinedKeyType = TypeCombinator::union($combinedKeyType, $keyType);
                    $combinedItemType = TypeCombinator::union($combinedItemType, $itemType);
                }
            }

            return new ArrayType($combinedKeyType, $combinedItemType);
        }

        return new MixedType();
    }

    /**
     * @param callable(Type): Type $cb
     */
    public function traverse(callable $cb): Type
    {
        $newTypes = [];
        $replace = false;

        foreach ($this->types as $type) {
            $newType = $cb($type);
            $newTypes[] = $newType;
            if ($newType !== $type && !$newType->equals($type)) {
                $replace = true;
            }
        }

        return $replace ? new self($newTypes) : $this;
    }

    public function traverseSimultaneously(Type $right, callable $cb): Type
    {
        return $this;
    }

    public function toPhpDocNode(): TypeNode
    {
        return new GenericTypeNode(new IdentifierTypeNode('array-merge'), array_map(static function (Type $type) {
            return $type->toPhpDocNode();
        }, $this->types));
    }

    /**
     * @param mixed[] $properties
     */
    public static function __set_state(array $properties): Type
    {
        $types = $properties['types'] ?? null;

        if (!is_array($types)) {
            return new self([new ErrorType()]);
        }

        $types = array_values($types);

        foreach ($types as $type) {
            if (!($type instanceof Type)) {
                return new self([new ErrorType()]);
            }
        }

        /** @phpstan-var list<Type> $types */

        if (count($types) <= 0) {
            return new self([new ErrorType()]);
        }

        return new self($types);
    }
}
