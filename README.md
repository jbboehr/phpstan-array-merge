
# phpstan-array-merge

[![ci](https://github.com/jbboehr/phpstan-array-merge/actions/workflows/ci.yml/badge.svg)](https://github.com/jbboehr/phpstan-array-merge/actions/workflows/ci.yml)
[![License: AGPL v3+](https://img.shields.io/badge/License-AGPL_v3%2b-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
![stability-experimental](https://img.shields.io/badge/stability-experimental-orange.svg)

## Installation

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
```

```php
<?php

\PHPStan\dumpType(ConstFixture::constMerge(['baz' => 'bat']));
```

```console
$ phpstan

 ------ --------------------------------------------
  Line   tmp.php
 ------ --------------------------------------------
  3      Dumped type: array{foo: 'bar', baz: 'bat'}
 ------ --------------------------------------------
```

If you mix generic arrays and array shapes, you get what is coming to you (or open an issue).

## License

This project is licensed under the [AGPL v3+](https://www.gnu.org/licenses/agpl-3.0) License - see the LICENSE.md file for details.
