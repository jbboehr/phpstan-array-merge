
# phpstan-array-merge

[![ci](https://github.com/jbboehr/phpstan-array-merge/actions/workflows/ci.yml/badge.svg)](https://github.com/jbboehr/phpstan-array-merge/actions/workflows/ci.yml)
[![License: AGPL v3 with Romic Exception](https://img.shields.io/badge/License-AGPL_v3_with_Romic_Exception-blue.svg)](#license)
![stability-experimental](https://img.shields.io/badge/stability-experimental-orange.svg)

## Installation

Requires PHP `^8.1` and PHPStan `^2.0.4`.

To use this extension, require it in [Composer](https://getcomposer.org/):

```bash
composer require --dev jbboehr/phpstan-array-merge
```

If you also install [phpstan/extension-installer](https://github.com/phpstan/extension-installer) then you're all set!

### Manual installation

If you don't want to use `phpstan/extension-installer`, include `extension.neon` in your project's PHPStan config:

```neon
includes:
    - vendor/jbboehr/phpstan-array-merge/extension.neon
```

## Usage

Have you ever wanted to have a function that performs an `array_merge()`-like operation but want that generic goodness
PHPStan has to offer? **WAIT NO MORE!**

<!-- akashi: compile-only -->

```php
<?php

class ConstFixture
{
    public const ARRAY = ['foo' => 'bar'];

    /**
     * @template T of array<mixed>
     * @param T $a
     * @phpstan-return array-merge<self::ARRAY, T>
     */
    public static function constMerge(array $a): array
    {
        return array_merge(self::ARRAY, $a);
    }
}

// @akashi-phpstan-error phpstan.dumpType: array{foo: 'bar', baz: 'bat'}
\PHPStan\dumpType(ConstFixture::constMerge(['baz' => 'bat']));
```

## License

This project is licensed under the GNU Affero General Public License version 3
with the Romic Exception (`AGPL-3.0-only WITH romic-exception`).

The Romic Exception permits linking or combining this extension with other code
without that other code becoming subject to the AGPL merely because of the
linking or combination. Modifications to the extension remain subject to the
AGPL, including its source-availability requirements for modified versions made
available to users over a computer network.

See [LICENSE.md](LICENSE.md) and [the Romic Exception](docs/LICENSE_EXCEPTION.md)
for the complete terms.

Alternative commercial licenses may be available from the
[Project Steward](docs/STEWARD.md). Contact John Boehr at <jbboehr@gmail.com>.
