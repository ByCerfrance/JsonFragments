# JsonFragments

[![Latest Version](https://img.shields.io/packagist/v/bycerfrance/json-fragments.svg?style=flat-square)](https://packagist.org/packages/bycerfrance/json-fragments)
![Packagist Dependency Version](https://img.shields.io/packagist/dependency-v/bycerfrance/json-fragments/php?version=dev-main&style=flat-square)
[![Software license](https://img.shields.io/github/license/ByCerfrance/JsonFragments.svg?style=flat-square)](https://github.com/ByCerfrance/JsonFragments/blob/main/LICENSE)
[![Build Status](https://img.shields.io/github/actions/workflow/status/ByCerfrance/JsonFragments/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/ByCerfrance/JsonFragments/actions/workflows/tests.yml?query=branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/bycerfrance/json-fragments.svg?style=flat-square)](https://packagist.org/packages/bycerfrance/json-fragments)

PHP library for externalizing JSON branches into immutable storage fragments,
inspecting references, and resolving their content lazily.

- **Package:** `bycerfrance/json-fragments`
- **Namespace:** `ByCerfrance\JsonFragments`
- **Requirements:** PHP 8.3 or later.

## Features

- Non-mutating JSON transformations using JSON Pointer paths.
- Reference value objects preserving `$ref` and sibling properties.
- Explicit resolvers deciding which references they support.
- Lazy fragments and reference inspection without storage access.
- One storage per storage resolver, using `jsonfragment://` by default.
- An optional Flysystem 3 adapter with immutable fragments, each assigned its own
  random identifier within the supplied filesystem.

## Installation

Install the library with [Composer](https://getcomposer.org/):

```bash
composer require bycerfrance/json-fragments
```

For the Flysystem integration and optional filesystem scoping:

```bash
composer require league/flysystem:^3.0 league/flysystem-path-prefixing:^3.3
```

Install the Flysystem adapter for your backend separately (for example,
`league/flysystem-aws-s3-v3` for S3). The core library does not require Flysystem.

## Construction and storage context

Configure the physical prefix using Flysystem's official `PathPrefixedAdapter`
(`league/flysystem-path-prefixing`). The fragment storage only owns the reference format:

```php
use ByCerfrance\JsonFragments\JsonFragmenter;
use ByCerfrance\JsonFragments\Storage\FlysystemFragmentStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;

// $adapter is a configured Flysystem adapter (S3, local, etc.).
$filesystem = new Filesystem(new PathPrefixedAdapter($adapter, 'datasets/weather-station'));
$fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($filesystem));

$data = [
    'title' => 'Weather observations',
    'unit' => 'celsius',
    'samples' => [
        ['time' => '08:00', 'temperature' => 12.5],
        ['time' => '09:00', 'temperature' => 14.2],
    ],
];
$compressed = $fragmenter->externalize($data, ['/samples']);
// The samples branch becomes: {"$ref":"jsonfragment://<fragment-id>.json"}
// The title and unit remain inline.
// Physical location: datasets/weather-station/<fragment-id>.json
```

Each new inline fragment gets a random 128-bit identifier. Supported existing
references are reused without I/O or existence checks. References are relative to
the supplied filesystem: the caller must restore the same filesystem context when
reading a persisted document. The reference does not encode its physical owner prefix.

Copying between filesystem contexts must be explicit: resolve using the source
fragmenter, then externalize the resulting values using the destination fragmenter.
Passing a source reference directly to a destination store does not copy its content.

Repeatedly externalizing inline data creates new fragments, even when the content
is identical. Reuse the returned references to avoid rewriting existing fragments.
There is no content-based deduplication.

Document persistence and storage cleanup belong to the integrating application.
The library has no framework or ORM dependency.

## Transformations

All methods accept **decoded JSON values**, including arrays, `stdClass`, scalars,
`JsonSerializable` values, references and lazy fragments. A string is a JSON string
value, not an encoded document. Decode textual JSON explicitly:

```php
$data = json_decode($encodedJson, associative: false, flags: JSON_THROW_ON_ERROR);
```

Using `associative: false` preserves the difference between JSON objects and lists,
including `{}` and `[]`. Stored JSON objects are resolved as `stdClass`; PHP
associative arrays are therefore not guaranteed to round-trip as PHP arrays.

| Method | Result | Storage access |
| --- | --- | --- |
| `externalize($data, $paths)` | New structure with `JsonReference` objects | Writes new fragments; may resolve existing nested references |
| `hydrate($data)` | New structure with supported references wrapped in `JsonFragment` | None |
| `dehydrate($data)` | New structure with fragments represented as `JsonReference` | None |
| `resolve($data)` | New structure with supported references replaced by their content | Reads as needed |
| `references($data)` | Generator of JSON Pointer => `JsonReference` occurrences | None |

The supplied structure is never modified. Arbitrary `JsonSerializable` values are
normalized by invoking their serialization method; their own side effects remain
the caller's responsibility. Existing fragments retain their lazy caches, which
may be populated during resolution. Object values returned from those caches are
defensive copies.

### Select branches using JSON Pointer

```php
$compressed = $fragmenter->externalize($data, ['/samples']);
```

- `''` selects the entire document; `'/'` selects an empty property name.
- Escape `/` as `~1` and `~` as `~0` in property names.
- Array indices must be non-negative integers without leading zeroes.
- Missing paths are ignored; existing `null` values are externalized.
- Duplicate or overlapping paths are rejected before writes.
- Wildcards and URI-fragment pointers such as `#/samples` are not supported.
- Pointers traverse inline arrays and objects, not the contents of lazy fragments
  or reference objects. Resolve those first to select a deeper branch.

Transformations enforce a maximum nesting depth of 128 levels and reject cyclic
input structures and unsupported PHP values such as resources.

### Serialize references or complete content

```php
// Build a lightweight JSON document containing references:
$prepared = $fragmenter->externalize($data, ['/samples']);
$encodedJson = json_encode($fragmenter->dehydrate($prepared), JSON_THROW_ON_ERROR);

// Reopen the document using the same filesystem context:
$decoded = json_decode($encodedJson, associative: false, flags: JSON_THROW_ON_ERROR);
$hydrated = $fragmenter->hydrate($decoded);

$title = $hydrated->title;                  // No storage read.
$samples = $hydrated->samples;              // JsonFragment; still no read.
$values = $samples->resolve();              // Reads once and caches the content.
$completeJson = json_encode($hydrated, JSON_THROW_ON_ERROR); // Complete content.

// Serialize the lightweight representation again, without reading any fragments:
$encodedJson = json_encode($fragmenter->dehydrate($hydrated), JSON_THROW_ON_ERROR);
```

`JsonReference::jsonSerialize()` emits the `$ref` envelope.
`JsonFragment::jsonSerialize()` emits the resolved value. Always use `dehydrate()`
when persisting a structure that may contain lazy fragments.

For eager resolution, use `$fragmenter->resolve($decoded)` instead of hydration.
References inside a loaded file are returned as data, not recursively resolved.
The library does not implement recursive JSON Schema or JSON Reference resolution.

## References and resolvers

```php
use ByCerfrance\JsonFragments\JsonReference;

$reference = new JsonReference(
    ref: 'https://example.org/schema.json',
    properties: ['description' => 'Dataset schema'],
);

$reference->getRef();        // Original identifier.
$reference->getScheme();     // "https"; null for a relative reference.
$reference->getProperties(); // Sibling properties, preserved on serialization.
```

A reference is a value object, not a request to access a resource. Its scheme is
derived from its identifier. The resolver decides whether it supports the complete
reference envelope.

The Flysystem implementation recognizes the configured reference prefix
(`jsonfragment://` by default) and accepts only envelopes without sibling
properties. Natural web references, local JSON Pointers and annotated references
are preserved. HTTP resources are never downloaded automatically.

```php
$store = new FlysystemFragmentStorage(
    $filesystem,
    referencePrefix: 'custom://fragments/',
);
```

This prefix identifies the reference format; it does not configure the filesystem
directory. Use the same format when reading stored documents.

### Inspect all references

```php
foreach ($fragmenter->references($data) as $pointer => $reference) {
    $identifier = $reference->getRef();
    $scheme = $reference->getScheme();
    $supported = $store->supports($reference);
}
```

Inspection reports every string-valued `$ref`, including unsupported references
and those inside sibling properties. Each occurrence is retained, even when several
paths refer to the same resource. Lazy fragments are inspected without being loaded.
Files referenced by the document are not traversed.

### Implement another storage or resolver

`Resolver\JsonReferenceResolverInterface` defines:

```php
public function supports(JsonReference $reference): bool;
public function resolve(JsonReference $reference): mixed;
```

`Storage\JsonFragmentStoreInterface` extends it with:

```php
public function store(mixed $value): JsonReference;
```

`JsonFragmenter` accepts a `JsonFragmentStoreInterface` directly. Reference formats,
storage keys and scope configuration belong to the implementation, not the
fragmenter. `supports()` must not perform I/O. `store()` must complete a new write
before returning its reference; it may reuse an existing supported reference
without reading it. `JsonFragment` can also use a read-only resolver directly.

## Failure and lifecycle contract

- Invalid JSON values and encodings fail explicitly; they are not converted to `null`.
- Unsupported references remain data. Supported but missing references fail on resolution.
- A failed lazy resolution is not cached and can be retried.
- A failed externalization may leave files from earlier successful writes. There is
  no rollback across storage operations.
- Reprocessing inline content generates new fragment IDs; abandoned writes require
  application-level cleanup.
- No operation deletes stored fragments. Delete a filesystem prefix only
  after the application has established that its data is no longer in use.
- The filesystem context is part of the document's persistence contract. References
  alone do not identify a bucket or physical prefix.

## Development

```bash
composer install
composer validate --strict
composer run test
composer run analyse
```

GitHub Actions is configured for PHP 8.3, 8.4 and 8.5, with lowest and current
stable dependencies.

See [CONTRIBUTING.md](CONTRIBUTING.md) for development conventions.

## License

[MIT](LICENSE).
