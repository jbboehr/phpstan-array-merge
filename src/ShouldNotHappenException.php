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

final class ShouldNotHappenException extends \RuntimeException
{
    private const URL = 'https://github.com/jbboehr/phpstan-array-merge/issues';

    /**
     * @throws self
     */
    public static function rethrow(\Throwable $e): never
    {
        throw new self($e->getMessage(), $e);
    }

    public function __construct(
        string $message = 'Internal error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('%s, please open an issue on GitHub %s', $message, self::URL),
            0,
            $previous,
        );
    }
}
