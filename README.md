# JsonFragments

PHP library for externalizing JSON branches into immutable storage fragments,
inspecting references, and resolving their content lazily.

- **Package:** `bycerfrance/json-fragments`
- **Namespace:** `ByCerfrance\JsonFragments`
- **Requirements:** PHP 8.3 or later.

## Status

The package is being bootstrapped. The runtime API is not implemented yet and no
release has been published. Usage examples will accompany the implementation.

## Intended scope

- Non-mutating JSON transformations using JSON Pointer paths.
- Reference value objects preserving `$ref` and sibling properties.
- Explicit resolvers deciding which references they support.
- Lazy fragments and reference inspection without storage access.
- One storage per storage resolver, using `jsonfragment://` by default.
- An optional Flysystem 3 adapter with immutable, content-addressed fragments
  scoped to a caller-provided storage prefix.

Database persistence, result immutability and storage cleanup belong to the
integrating application. The library has no framework or ORM dependency.

## Development

```bash
composer install
composer validate --strict
composer run test
composer run analyse
```

Test and analysis commands become meaningful when source files and tests are added.
GitHub Actions is configured for PHP 8.3, 8.4 and 8.5, with lowest and current
stable dependencies.

See [CONTRIBUTING.md](CONTRIBUTING.md) for development conventions.

## License

[MIT](LICENSE).
