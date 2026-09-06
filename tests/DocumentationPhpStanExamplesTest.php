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

use jbboehr\Akashi\Example;
use jbboehr\Akashi\Integration\PHPStan\PhpStanExampleConfiguration;
use jbboehr\Akashi\Integration\PHPStan\VerifiesPhpStanExamples;
use jbboehr\Akashi\Source\DocumentationSource;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DocumentationDumpTypeRule> */
final class DocumentationPhpStanExamplesTest extends RuleTestCase
{
    use VerifiesPhpStanExamples;

    protected function getRule(): Rule
    {
        return new DocumentationDumpTypeRule();
    }

    public function testReadmeExamples(): void
    {
        self::getContainer();

        $projectRoot = \dirname(__DIR__);
        $corpus = DocumentationSource::forProject($projectRoot)
            ->includeFile('README.md')
            ->load();
        $configuration = PhpStanExampleConfiguration::forProject(
            $projectRoot,
            static fn(Example $example): bool => 'README.md' === $example->codeOrigin()->document->path->value,
        );

        $this->assertPhpStanExamples($corpus, $configuration);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }
}
