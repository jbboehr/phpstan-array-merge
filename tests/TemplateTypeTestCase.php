<?php
/**
 * Copyright (c) anno Domini nostri Jesu Christi MMXXVI John Boehr & contributors
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
