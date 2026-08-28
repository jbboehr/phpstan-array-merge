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

use jbboehr\PHPStan\ArrayMerge\ArrayMergeTypeNodeResolverExtension;
use jbboehr\PHPStan\ArrayMerge\ShouldNotHappenException;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * @note The PHPStan example used a dataProvider which caused issues with code coverage
 */
final class ArrayMergeTypeNodeResolverExtensionTest extends TypeInferenceTestCase
{
    /**
     * @return \Generator<array{string}>
     */
    public static function dataFileProvider(): \Generator
    {
        yield [__DIR__ . '/data/invalid.php'];
        yield [__DIR__ . '/data/basic.php'];
        yield [__DIR__ . '/data/constant-unions.php'];
        yield [__DIR__ . '/data/generic.php'];
        yield [__DIR__ . '/data/generic-constant-list.php'];
        yield [__DIR__ . '/data/generic-const.php'];
        yield [__DIR__ . '/data/mixed-shapes.php'];
        yield [__DIR__ . '/data/mixed-lists.php'];
        yield [__DIR__ . '/data/multi-const-var.php'];
        yield [__DIR__ . '/data/nested.php'];
        yield [__DIR__ . '/data/non-empty.php'];
        yield [__DIR__ . '/data/numeric-keys.php'];
        yield [__DIR__ . '/data/optional-lists.php'];
    }

    /**
     * @dataProvider dataFileProvider
     */
    #[DataProvider('dataFileProvider')]
    public function testBasic(string $file): void
    {
        foreach (self::safeGatherAssertTypes($file) as $assert) {
            $this->assertFileAsserts(...$assert);
        }
    }

    /**
     * @param string $file
     * @return array<string, array{string, string, string, string, int}>
     */
    public static function safeGatherAssertTypes(string $file): array
    {
        return array_map(
            self::validateAssertType(...),
            self::gatherAssertTypes($file),
        );
    }

    /**
     * @return array{string, string, string, string, int}
     */
    private static function validateAssertType(mixed $args): array
    {
        self::assertIsArray($args);
        self::assertCount(5, $args);
        self::assertIsString($args[0]);
        self::assertIsString($args[1]);
        self::assertIsString($args[2]);
        self::assertIsString($args[3]);
        self::assertIsInt($args[4]);

        return [
            $args[0],
            $args[1],
            self::expectedTypeForPhpStanRenderer($args[2]),
            $args[3],
            $args[4],
        ];
    }

    /**
     * Normalize renderer-only differences across the supported PHPStan versions.
     */
    private static function expectedTypeForPhpStanRenderer(string $expectedType): string
    {
        if (str_starts_with($expectedType, 'list{') && self::usesArrayKeywordForListShapes()) {
            $expectedType = 'array{' . substr($expectedType, 5);
        }

        if (str_contains($expectedType, "'08':") && self::usesUnquotedNonIdentifierStringKeys()) {
            $expectedType = str_replace("'08':", '08:', $expectedType);
        }

        return match ($expectedType) {
            'array<mixed, mixed>', 'array<mixed>' => self::usesImplicitMixedArrayDescription()
                ? 'array'
                : $expectedType,
            default => $expectedType,
        };
    }

    private static function usesImplicitMixedArrayDescription(): bool
    {
        $arrayType = new ArrayType(new MixedType(true), new MixedType(true));

        return 'array' === $arrayType->describe(VerbosityLevel::precise());
    }

    private static function usesArrayKeywordForListShapes(): bool
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();
        $itemType = new IntegerType();
        $builder->setOffsetValueType(null, $itemType);
        $builder->setOffsetValueType(null, $itemType, true);
        $builder->setOffsetValueType(null, $itemType, true);

        return str_starts_with($builder->getArray()->describe(VerbosityLevel::precise()), 'array{');
    }

    private static function usesUnquotedNonIdentifierStringKeys(): bool
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();
        $builder->setOffsetValueType(new ConstantStringType('08'), new IntegerType());

        return 'array{08: int}' === $builder->getArray()->describe(VerbosityLevel::precise());
    }

    public function testExceptionConversion(): void
    {
        $resolver = new ArrayMergeTypeNodeResolverExtension();

        /** @phpstan-ignore-next-line argument.type */
        $typeNode = new GenericTypeNode(new IdentifierTypeNode('array-merge'), [1, 2, 3]);

        $this->expectException(ShouldNotHappenException::class);

        $resolver->resolve($typeNode, new NameScope(null, []));
    }

    public function testEmptyGenericTypesReturnsNull(): void
    {
        $resolver = new ArrayMergeTypeNodeResolverExtension();

        $typeNode = new GenericTypeNode(new IdentifierTypeNode('array-merge'), []);

        $this->assertNull($resolver->resolve($typeNode, new NameScope(null, [])));
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../extension.neon'];
    }
}
