<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXVI John Boehr & contributors
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

namespace jbboehr\PHPStan\ArrayMerge\Tests;

use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\Generic\TemplateTypeVarianceMap;
use PHPStan\Type\Type;

abstract class TemplateTypeTestCase extends PHPStanTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }

    protected function createTemplate(
        string $functionName,
        string $name,
        Type $bound,
    ): TemplateType {
        $scope = (new \ReflectionMethod(TemplateTypeScope::class, 'createWithFunction'))
            ->invoke(null, $functionName);
        $this->assertInstanceOf(TemplateTypeScope::class, $scope);
        $template = (new \ReflectionMethod('PHPStan\Type\Generic\TemplateTypeFactory', 'create'))->invoke(
            null,
            $scope,
            $name,
            $bound,
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(TemplateType::class, $template);

        return $template;
    }

    protected function resolveTemplateTypes(Type $type, TemplateType $from, Type $to): Type
    {
        $result = (new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeHelper',
            'resolveTemplateTypes',
        ))->invoke(
            null,
            $type,
            new TemplateTypeMap([$from->getName() => $to]),
            TemplateTypeVarianceMap::createEmpty(),
            TemplateTypeVariance::createInvariant(),
        );
        $this->assertInstanceOf(Type::class, $result);

        return $result;
    }

    protected function resolveToBounds(Type $type): Type
    {
        $result = (new \ReflectionMethod(
            'PHPStan\Type\Generic\TemplateTypeHelper',
            'resolveToBounds',
        ))->invoke(null, $type);
        $this->assertInstanceOf(Type::class, $result);

        return $result;
    }
}
