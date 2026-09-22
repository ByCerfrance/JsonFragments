# Contributing

## Setup

Use PHP 8.3 or later and Composer 2:

```bash
composer install
```

The core must remain framework-independent. Flysystem is an optional runtime
integration and is installed as a development dependency for adapter tests.

## Conventions

- Use `declare(strict_types=1)` in every PHP file.
- Use the `ByCerfrance\JsonFragments` namespace and PSR-4 file layout.
- Use four spaces, LF line endings and a maximum line length of 120 characters.
- Put class and method opening braces on the next line.
- Sort imports alphabetically and declare return types on all methods.
- Prefer readonly value objects and immutable public contracts.
- Use `#[Override]` for interface implementations.
- Document public contracts and exception behavior in English.

## Checks

```bash
composer validate --strict
composer run test
composer run analyse
```

Tests belong in `tests/`, mirroring `src/`. PHPUnit coverage metadata is required:
declare covered and used classes with `#[CoversClass]` and `#[UsesClass]`.
Use in-memory storage for tests; credentials and network storage are not required.

Add a changelog entry for user-visible changes and open a pull request on GitHub.

## Releases

Releases use Semantic Versioning and Git tags named `vX.Y.Z`. Update the changelog
before tagging. Composer distribution through Packagist requires registering the
GitHub repository and configuring its update integration separately.
