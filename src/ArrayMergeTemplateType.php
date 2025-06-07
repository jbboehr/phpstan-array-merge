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

use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeFactory;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Generic\TemplateTypeStrategy;
use PHPStan\Type\Generic\TemplateTypeTrait;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\Traits\UndecidedComparisonCompoundTypeTrait;
use PHPStan\Type\Type;

/**
 * @phpstan-ignore-next-line phpstanApi.interface
 */
final class ArrayMergeTemplateType extends ArrayMergeType implements TemplateType
{
    /**
     * @use TemplateTypeTrait<ArrayMergeType>
     * @phpstan-ignore-next-line phpstanApi.trait
     */
    use TemplateTypeTrait;

    /** @phpstan-ignore-next-line phpstanApi.trait */
    use UndecidedComparisonCompoundTypeTrait;

    /**
     * @param non-empty-string $name
     */
    public function __construct(
        TemplateTypeScope $scope,
        TemplateTypeStrategy $templateTypeStrategy,
        TemplateTypeVariance $templateTypeVariance,
        string $name,
        ArrayMergeType $bound,
    ) {
        parent::__construct($bound->getTypes());
        $this->scope = $scope;
        $this->strategy = $templateTypeStrategy;
        $this->variance = $templateTypeVariance;
        $this->name = $name;
        $this->bound = $bound;
    }

    protected function getResult(): Type
    {
        $result = $this->getBound()->getResult();

        /** @phpstan-ignore-next-line phpstanApi.method */
        return TemplateTypeFactory::create(
            $this->getScope(),
            $this->getName(),
            $result,
            $this->getVariance(),
            $this->getStrategy(),
        );
    }

    protected function shouldGeneralizeInferredType(): bool
    {
        return false;
    }
}
