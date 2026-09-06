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

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;
use function strtolower;

/** @implements Rule<FuncCall> */
final class DocumentationDumpTypeRule implements Rule
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || 'phpstan\\dumptype' !== strtolower($node->name->toString())) {
            return [];
        }

        $argument = $node->getArgs()[0] ?? null;
        if (null === $argument) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Dumped type: ' . $scope->getType($argument->value)->describe(VerbosityLevel::precise()),
            )->identifier('phpstan.dumpType')->build(),
        ];
    }
}
