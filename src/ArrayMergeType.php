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

use Closure;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Traits\LateResolvableTypeTrait;
use PHPStan\Type\Traits\NonGeneralizableTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use function array_fill_keys;
use function is_callable;
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
        $nConstantArrays = 0;
        $nOtherArrays = 0;
        $hasUninhabitedArray = false;
        $types = [];

        foreach ($this->types as $type) {
            $type = self::removeUninhabitedConstantArrays($type);
            $type = self::removeTopLevelNeverAlternatives($type);

            if ($type instanceof NeverType) {
                $hasUninhabitedArray = true;
                $types[] = $type;
                continue;
            }

            $isArray = $type->isArray();

            if (!$isArray->yes()) {
                return new ErrorType();
            }

            if (
                $type->getIterableKeyType() instanceof NeverType
                || $type->getIterableValueType() instanceof NeverType
            ) {
                if ($type->isIterableAtLeastOnce()->yes()) {
                    $hasUninhabitedArray = true;
                } else {
                    $type = ConstantArrayTypeBuilder::createEmpty()->getArray();
                }
            }

            $types[] = $type;

            if ($type->isConstantArray()->yes()) {
                $nConstantArrays++;
            } elseif ($type->isArray()->yes()) {
                $nOtherArrays++;
            }
        }

        if ($hasUninhabitedArray) {
            return new NeverType();
        }

        if (count($types) === 1) {
            $constantArrays = $types[0]->getConstantArrays();

            if (count($constantArrays) === 1 && $types[0]->equals($constantArrays[0])) {
                $constantArray = $constantArrays[0];
                $allStringKeys = true;

                foreach ($constantArray->getKeyTypes() as $keyType) {
                    $constantStrings = $keyType->getConstantStrings();
                    $stringKey = count($constantStrings) === 1 ? $constantStrings[0]->getValue() : null;

                    if (
                        null === $stringKey
                        || !$keyType->equals($constantStrings[0])
                        || (string) (int) $stringKey === $stringKey
                    ) {
                        $allStringKeys = false;
                        break;
                    }
                }

                if (
                    $allStringKeys
                    && [0] === $constantArray->getNextAutoIndexes()
                    && !self::hasUnknownExtraOffsets($constantArray)
                ) {
                    return $constantArray;
                }
            }
        }

        if ($nConstantArrays === count($types)) {
            $normalizedTypes = [];
            $allNormalizedTypesConstant = true;

            foreach ($types as $type) {
                $normalizedType = self::normalizeConstantArrayIntegerKeys($type);
                if (null === $normalizedType) {
                    return new MixedType();
                }

                $normalizedTypes[] = $normalizedType;
                if (!$normalizedType->isConstantArray()->yes()) {
                    $allNormalizedTypesConstant = false;
                }

                foreach ($type->getConstantArrays() as $constantArrayType) {
                    if (self::hasUnknownExtraOffsets($constantArrayType)) {
                        $allNormalizedTypesConstant = false;
                        break;
                    }
                }
            }

            if ($allNormalizedTypesConstant) {
                $builder = ConstantArrayTypeBuilder::createEmpty();

                foreach ($normalizedTypes as $normalizedType) {
                    foreach (self::getConstantArrayKeyTypes($normalizedType) as $keyType) {
                        $builder->setOffsetValueType(
                            $keyType instanceof ConstantIntegerType ? null : $keyType,
                            $normalizedType->getOffsetValueType($keyType),
                            !$normalizedType->hasOffsetValueType($keyType)->yes(),
                        );
                    }
                }

                return $builder->getArray();
            }
        }

        if ($nConstantArrays + $nOtherArrays === count($types)) {
            $allIntegerKeys = true;
            $atLeastOneNonEmpty = false;
            $combinedKeyType = null;
            $combinedItemType = null;

            foreach ($types as $type) {
                $arrayKeyTypes = [];
                $arrayItemTypes = [];

                foreach ($type->getArrays() as $arrayType) {
                    $normalizedArrayType = self::normalizeConstantArrayIntegerKeys($arrayType);

                    if (null === $normalizedArrayType) {
                        $arrayKeyTypes[] = self::normalizeArrayMergeKeyType($arrayType->getKeyType());
                    } else {
                        $arrayKeyTypes[] = $normalizedArrayType->getIterableKeyType();

                        $constantArrays = $arrayType->getConstantArrays();
                        if (count($constantArrays) === 1) {
                            $unsealedTypes = self::getUnsealedTypes($constantArrays[0]);
                            if (null !== $unsealedTypes) {
                                $arrayKeyTypes[] = self::normalizeArrayMergeKeyType($unsealedTypes[0]);
                            }
                        }
                    }

                    $arrayItemTypes[] = $arrayType->getItemType();
                }

                if ([] === $arrayKeyTypes) {
                    return new ArrayType(new MixedType(true), new MixedType(true));
                }

                if ($type->isIterableAtLeastOnce()->yes()) {
                    $atLeastOneNonEmpty = true;
                }

                $keyType = TypeCombinator::union(...$arrayKeyTypes);
                $itemType = TypeCombinator::union(...$arrayItemTypes);
                $allIntegerKeys = $allIntegerKeys && (new IntegerType())->isSuperTypeOf($keyType)->yes();

                if (!($keyType instanceof MixedType) && !$keyType->isInteger()->no()) {
                    $keyType = TypeCombinator::union(
                        TypeCombinator::remove($keyType, new IntegerType()),
                        IntegerRangeType::fromInterval(0, null),
                    );
                }

                if (null === $combinedKeyType) {
                    $combinedKeyType = $keyType;
                    $combinedItemType = $itemType;
                } else {
                    $combinedKeyType = TypeCombinator::union($combinedKeyType, $keyType);
                    $combinedItemType = TypeCombinator::union($combinedItemType, $itemType);
                }
            }

            $result = new ArrayType($combinedKeyType, $combinedItemType);

            if ($allIntegerKeys) {
                $result = TypeCombinator::intersect($result, new AccessoryArrayListType());
            }

            return $atLeastOneNonEmpty
                ? TypeCombinator::intersect($result, new NonEmptyArrayType())
                : $result;
        }

        return new ArrayType(new MixedType(true), new MixedType(true));
    }

    private static function removeTopLevelNeverAlternatives(Type $type): Type
    {
        if (!($type instanceof UnionType) || $type instanceof TemplateType) {
            return $type;
        }

        $innerTypes = $type->getTypes();
        $types = array_values(array_filter(
            $innerTypes,
            static fn(Type $innerType): bool => !($innerType instanceof NeverType),
        ));

        if (count($types) === count($innerTypes)) {
            return $type;
        }

        if ([] === $types) {
            return $innerTypes[0];
        }

        if (count($types) === 1) {
            return $types[0];
        }

        return $type instanceof BenevolentUnionType
            ? new BenevolentUnionType($types)
            : new UnionType($types);
    }

    private static function normalizeArrayMergeKeyType(Type $keyType): Type
    {
        return $keyType instanceof MixedType ? $keyType : $keyType->toArrayKey();
    }

    private static function removeUninhabitedConstantArrays(Type $type): Type
    {
        return TypeTraverser::map($type, static function (Type $innerType, callable $traverse): Type {
            $innerType = $traverse($innerType);

            if ($innerType instanceof ConstantArrayType) {
                $hasOptionalNever = false;
                $hasIntegerKey = false;
                $keyTypes = [];
                $valueTypes = [];
                $optionalKeys = [];
                $optionalKeyLookup = array_fill_keys($innerType->getOptionalKeys(), true);

                foreach ($innerType->getKeyTypes() as $i => $keyType) {
                    $valueType = $innerType->getValueTypes()[$i];
                    $hasIntegerKey = $hasIntegerKey || $keyType instanceof ConstantIntegerType;
                    $isOptionalKey = isset($optionalKeyLookup[$i]);

                    if ($valueType instanceof NeverType) {
                        if (!$isOptionalKey) {
                            return new NeverType();
                        }

                        $hasOptionalNever = true;
                        continue;
                    }

                    if ($isOptionalKey) {
                        $optionalKeys[] = count($keyTypes);
                    }

                    $keyTypes[] = $keyType;
                    $valueTypes[] = $valueType;
                }

                if (!$hasOptionalNever) {
                    return $innerType;
                }

                if (self::hasUnknownExtraOffsets($innerType)) {
                    return $innerType;
                }

                if ([0] !== $innerType->getNextAutoIndexes()) {
                    return $innerType;
                }

                if ($hasIntegerKey) {
                    return $innerType;
                }

                return new ConstantArrayType(
                    $keyTypes,
                    $valueTypes,
                    $innerType->getNextAutoIndexes(),
                    $optionalKeys,
                    [] === $keyTypes ? TrinaryLogic::createYes() : $innerType->isList(),
                    self::getUnsealedTypes($innerType),
                );
            }

            return $innerType;
        });
    }

    private static function getOptionalMethod(object $object, string $method): ?Closure
    {
        $callable = [$object, $method];

        if (!is_callable($callable)) {
            return null;
        }

        return Closure::fromCallable($callable);
    }

    /**
     * @return array{Type, Type}|null
     */
    private static function getUnsealedTypes(ConstantArrayType $type): ?array
    {
        $getUnsealedTypes = self::getOptionalMethod($type, 'getUnsealedTypes');

        if (null === $getUnsealedTypes) {
            return null;
        }

        $unsealedTypes = $getUnsealedTypes();

        if (
            !is_array($unsealedTypes)
            || count($unsealedTypes) !== 2
            || !$unsealedTypes[0] instanceof Type
            || !$unsealedTypes[1] instanceof Type
        ) {
            return null;
        }

        return [$unsealedTypes[0], $unsealedTypes[1]];
    }

    private static function hasUnknownExtraOffsets(ConstantArrayType $type): bool
    {
        $unsealedTypes = self::getUnsealedTypes($type);

        if (null === $unsealedTypes) {
            return false;
        }

        return !($unsealedTypes[0] instanceof NeverType && $unsealedTypes[0]->isExplicit());
    }

    private static function normalizeConstantArrayIntegerKeys(Type $type): ?Type
    {
        $normalizedTypes = [];

        foreach ($type->getConstantArrays() as $constantArrayType) {
            $builder = ConstantArrayTypeBuilder::createEmpty();
            $keyTypes = $constantArrayType->getKeyTypes();
            $valueTypes = $constantArrayType->getValueTypes();

            if (count($keyTypes) !== count($valueTypes)) {
                return null;
            }

            foreach ($keyTypes as $i => $keyType) {
                $builder->setOffsetValueType(
                    $keyType instanceof ConstantIntegerType ? null : $keyType,
                    $valueTypes[$i],
                    $constantArrayType->isOptionalKey($i),
                );
            }

            $normalizedTypes[] = $builder->getArray();
        }

        return [] === $normalizedTypes ? null : TypeCombinator::union(...$normalizedTypes);
    }

    /**
     * @return array<int|string, ConstantIntegerType|ConstantStringType>
     */
    private static function getConstantArrayKeyTypes(Type $type): array
    {
        $keyTypes = [];

        foreach ($type->getConstantArrays() as $constantArrayType) {
            foreach ($constantArrayType->getKeyTypes() as $keyType) {
                $keyTypes[$keyType->getValue()] = $keyType;
            }
        }

        return $keyTypes;
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
            if ($newType !== $type) {
                $replace = true;
            }
        }

        return $replace ? new self($newTypes) : $this;
    }

    public function traverseSimultaneously(Type $right, callable $cb): Type
    {
        if (!$right instanceof self || count($this->types) !== count($right->types)) {
            return $this;
        }

        $newTypes = [];
        $replace = false;

        foreach ($this->types as $i => $type) {
            $newType = $cb($type, $right->types[$i]);
            $newTypes[] = $newType;
            if ($newType !== $type) {
                $replace = true;
            }
        }

        return $replace ? new self($newTypes) : $this;
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
            return new ErrorType();
        }

        $types = array_values($types);

        foreach ($types as $type) {
            if (!($type instanceof Type)) {
                return new ErrorType();
            }
        }

        /** @phpstan-var list<Type> $types */

        if (count($types) <= 0) {
            return new ErrorType();
        }

        return new self($types);
    }
}
