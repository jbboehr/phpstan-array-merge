# Changelog

Notable changes are recorded here using the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.
Version numbers follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-09-06

### Added

- Inference for nested `array-merge<...>` expressions and unions of array operands.
- Inference for merges combining generic arrays with array shapes and lists.
- Regression tests comparing inferred types with native PHP array operations,
  plus executable README examples.
- [Contribution licensing terms](CONTRIBUTING.md).

### Changed

- **Breaking:** Require PHPStan `^2.0.4`. The PHP requirement remains `^8.1`.
- Use wider array types when a single shape cannot represent the possible key
  orders. Re-run PHPStan after upgrading, since corrected inference can change
  diagnostics.
- Change the project license from `AGPL-3.0+` to
  `AGPL-3.0-only WITH romic-exception`. See the [license](LICENSE.md) and
  [Romic Exception](docs/LICENSE_EXCEPTION.md) for the terms.

### Fixed

- Reindex integer keys in iteration order, including sparse and negative keys
  in single-operand merges.
- Infer lists for integer-only inputs and account for optional list elements.
- Preserve string-key overwrite behavior and account for possible differences
  in insertion order.
- Preserve nonempty results when any operand is guaranteed to be nonempty,
  while retaining empty outcomes when all operands may be empty.
- Preserve unresolved templates and nested union semantics during type
  traversal, export, and PHPDoc round-trips.
- Handle impossible (`never`) operands and fields, and reject operands that are
  not definitely arrays, including `mixed`, nullable arrays, and unions with
  non-array branches.

## [0.1.0] - 2025-06-07

### Added

- Initial release of the `array-merge<...>` PHPDoc type for generic wrappers
  around `array_merge()`.
- Extension registration through `phpstan/extension-installer` or a manual
  `extension.neon` include.

[Unreleased]: https://github.com/jbboehr/phpstan-array-merge/compare/v0.2.0...develop
[0.2.0]: https://github.com/jbboehr/phpstan-array-merge/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/jbboehr/phpstan-array-merge/releases/tag/v0.1.0
