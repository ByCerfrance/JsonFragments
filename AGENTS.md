# AGENTS.md

## Overview

`bycerfrance/json-fragments` is a framework-independent PHP 8.3+ library.
Sources use `ByCerfrance\JsonFragments\` in `src/`; tests use
`ByCerfrance\JsonFragments\Tests\` in `tests/`.

## Commands

```bash
composer install
composer validate --strict
composer run test
composer run analyse
```

## Conventions

- Follow CONTRIBUTING.md and .editorconfig.
- One class per file; strict types, explicit return types, sorted imports.
- Prefer readonly value objects; lazy caches may have private mutable state.
- Use SPL exceptions and `#[Override]` on interface implementations.
- Tests require PHPUnit 12 coverage metadata.
- Keep JSON transformations non-mutating and preserve object/list distinctions.
- Reference inspection and hydration must not access storage.
- Only explicitly supported references may be resolved; preserve other references.
- Do not introduce ORM, container or application-specific lifecycle dependencies.
- Never commit credentials, vendor files or the library's local composer.lock.
- CI is GitHub Actions, not GitLab CI. Do not publish or tag without an explicit request.
