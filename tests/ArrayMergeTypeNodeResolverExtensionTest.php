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

namespace jbboehr\PHPStan\ArrayMerge\Tests;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ArrayMergeTypeNodeResolverExtensionTest extends TypeInferenceTestCase
{
    /**
     * @return \Generator<string, mixed[]>
     */
    public static function dataFileAsserts(): \Generator
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/basic.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/generic.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/generic-constant-list.php');
    }

    /**
     * @note The PHPStan example used a dataProvider which caused issues with code coverage
     */
    public function testFileAsserts(): void
    {
        foreach (self::dataFileAsserts() as $data) {
            $this->assertGreaterThan(2, count($data));

            [$assertType, $file] = $data;
            $args = array_slice($data, 2);

            $this->assertIsString($assertType);
            $this->assertIsString($file);

            $this->assertFileAsserts($assertType, $file, ...$args);
        }
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }
}
