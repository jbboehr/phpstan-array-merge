# Dependency compatibility

The supported requirements are PHP `^8.1` and PHPStan `^2.0.4`.
PHPStan 1.x is no longer supported. This dependency restriction is a breaking
compatibility change for users on 1.x or PHPStan 2.0.0 through 2.0.3.

The PHPStan floor matches the exact version exercised by the compatibility CI
job. The regular test jobs resolve current dependencies permitted by Composer;
the compatibility job pins PHPStan 2.0.4, phpstan-phpunit 2.0.6, and
phpstan-strict-rules 2.0.4 on PHP 8.1.

Supporting only 2.x removes the separate 1.x development dependency constraints
and strict-rules configuration branch. PHPStan also recommends targeting one
major version in its [extension upgrade guide](https://phpstan.org/blog/upgrading-from-phpstan-1-to-2).

Keep capability checks that cover differences within 2.x. In particular,
unsealed array shapes were introduced in
[PHPStan 2.2](https://phpstan.org/blog/phpstan-2-2-unsealed-array-shapes-safer-array-keys),
so requiring 2.0.4 does not make the unsealed-shape fallbacks obsolete. Renderer
differences within 2.x still matter to the test fixtures as well.

When changing the minimum again, update Composer requirements, the pinned CI
case, and the installation requirements together. Run the complete suite,
including the [behavior contract](behavior-contract.md), on the proposed floor
and the installed current version before removing compatibility code. Keep
changes to inference behavior in a separate slice.
