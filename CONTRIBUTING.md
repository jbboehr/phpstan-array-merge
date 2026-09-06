# Contributing

Bug reports, documentation improvements, tests, and code changes are welcome.
Keep pull requests focused, explain what changes and why, and identify any
third-party material and its license.

AI-assisted contributions are permitted. You remain responsible for reviewing
the submitted material and ensuring that you have the right to license it under
these terms.

## Checks

Install dependencies and run the project checks:

```sh
composer install
composer validate --strict
composer phpunit -- --no-coverage
composer phpstan -- clear-result-cache
composer phpstan -- analyze --no-progress
composer phpcs
git diff --check
```

Clear PHPStan's result cache when checking changes to the extension, since cached
results can hide new diagnostics in unchanged fixtures. Run `nix flake check -L`
when changing Nix or CI configuration.

See the [behavior contract](docs/development/behavior-contract.md) for focused
test commands and the [compatibility notes](docs/development/compatibility.md)
for the supported dependency versions.

## Definitions

The project as a whole is distributed under:

```text
AGPL-3.0-only WITH romic-exception
```

This is the **Project License**.

The license for contributor-authored material is:

```text
AGPL-3.0-only WITH romic-exception OR Apache-2.0
```

This is the **Contribution License**.

A **Contribution** is copyrightable material intentionally submitted for inclusion in the project, including code,
documentation, tests, configuration, and artwork.

Issue reports, feature requests, general discussion, and material conspicuously marked **“Not a Contribution”** are not
Contributions under these terms.

The **Project Steward** is the individual or legal entity identified in [`docs/STEWARD.md`](docs/STEWARD.md).

## Contribution terms

By intentionally submitting a Contribution to this repository through a pull request or another contribution mechanism
that provides notice of these terms, you license the Contribution to every recipient under the Contribution License.

Under these terms:

- you retain copyright in your Contribution;
- each recipient may use your Contribution under either listed license;
- the public project may incorporate your Contribution under the Project License;
- the Project Steward may use your Contribution under Apache-2.0, including in separately licensed or proprietary
  versions;
- every other recipient receives the same Apache-2.0 option;
- the Apache-2.0 option applies only to material that you have the right to license; and
- your Contribution does not cause the remainder of the project to become licensed under Apache-2.0.

## Unauthorized material

The Contribution License does not grant rights in material that the submitter had no authority to license.

A false representation of ownership or authority does not bind the actual copyright holder or cure an unauthorized
submission.

If a Contribution contains material that was not validly submitted or licensed, the Project Steward may remove, replace,
or seek separate permission for that material.

## Your authority to contribute

By submitting a Contribution, you represent that:

1. you created the Contribution or otherwise have sufficient rights to submit it under the applicable terms;
2. if another person or organization owns any portion of the Contribution, you are authorized to submit and license that
   portion;
3. you have obtained any necessary permission from your employer or another rights holder;
4. you have identified any third-party material included in the Contribution and its applicable license; and
5. you are not knowingly submitting material that cannot lawfully be incorporated into the project.

Do not submit code copied from another project merely because that project is publicly accessible. Identify its source
and license so that compatibility can be reviewed.

## Copyright

You retain copyright in your Contribution. Submission does not assign copyright to the Project Steward.

Accepted contributions may be acknowledged collectively using a notice such as:

```text
Copyright (c) [YEAR] [PROJECT_STEWARD] & contributors
```

Individual copyright notices may be retained where legally required or reasonably appropriate.

## Acceptance

The Project Steward may accept, reject, request changes to, or decline to incorporate any Contribution.

For unusually large contributions, corporate contributions, or contributions with unclear provenance, the Project
Steward may request additional confirmation of ownership or authority before merging.
