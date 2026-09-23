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
- Progressive JSON output as a standard PHP stream resource, ready to wrap in a PSR-7 body.
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
| `stream($data)` | Readable PHP resource producing resolved JSON | Reads lazily as the output is consumed |

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

## Stream complete JSON with bounded fragment reads

Use `stream()` when the output is being transmitted or exported rather than manipulated
as PHP values. Unlike `resolve()` followed by `json_encode()`, it does not materialize
the complete resolved document or its encoded output.

```php
// $decoded contains the lightweight document and its references.
// $destination is an already-open writable stream, such as an export file.
$stream = $fragmenter->stream($decoded);

try {
    stream_copy_to_stream($stream, $destination);
} finally {
    fclose($stream);
}
```

Opening the output performs no fragment reads or inline serialization. Reading it
starts a lazy producer that emits JSON punctuation, inline values and the content
of supported references. PHP may read ahead by a small stream buffer, so a small
`fread()` can cause a bounded amount of additional production.

The output is a read-only, non-seekable resource with unknown size. Rewinding and
random access are not supported. Multiple output resources can be consumed independently.
Keep the supplied input unchanged while its stream is open: streaming deliberately
does not take a deep snapshot of the input document.

### Native streaming and fallback

`Resolver\StreamingJsonReferenceResolverInterface` extends the standard resolver:

```php
/** @return resource */
public function readStream(JsonReference $reference);
```

`FlysystemFragmentStorage` implements both `JsonFragmentStoreInterface` and this
streaming interface. Its `readStream()` delegates directly to Flysystem's
`readStream()`. Its `resolve()` reads that stream completely, decodes the JSON and
closes the resource.

For output produced by `JsonFragmenter::stream()`:

- A streaming resolver supplies a blocking, readable resource containing one complete
  JSON value. Its bytes are forwarded without decoding or re-encoding the fragment.
- A classic resolver remains supported: the producer calls `resolve()` and encodes
  its returned PHP value progressively. This fallback materializes that fragment in memory.
- A `JsonFragment` uses its own bound resolver and original storage context. Native
  streaming reads the storage directly, without populating or using the fragment's decoded cache.
- Unsupported references retain their envelopes and sibling properties.
- References inside resolved content are not followed recursively.

The native path trusts the resolver to supply valid JSON. It does not validate a
file's full contents before sending them; malformed stored JSON will produce malformed
output. Inline values and fallback values are encoded with `JSON_THROW_ON_ERROR`.

Only the current fragment stream is opened. It is consumed from its current position,
without rewinding, and closed at EOF, on an error or when the output resource is closed.
The resolver transfers ownership of each returned resource to the consumer.

Memory overhead for native fragments consists primarily of reading buffers and traversal
state. The input's inline values are already in memory; large inline strings still need
an encoded string allocation. Custom serializers, fallback resolvers and underlying
filesystem adapters can also allocate memory. Calling `stream_get_contents()` on the
output or collecting all chunks into a string materializes the complete result again.

### Use as a PSR-7 response body

```php
// $streamFactory implements Psr\Http\Message\StreamFactoryInterface (PSR-17).
$resource = $fragmenter->stream($decoded);

try {
    $body = $streamFactory->createStreamFromResource($resource);
} catch (\Throwable $exception) {
    fclose($resource);
    throw $exception;
}

$response = $response
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body);
```

Ownership passes to the PSR-7 body after successful construction; closing it closes
the output and any currently open fragment. The HTTP emitter must read the body in
chunks rather than cast it to a string. Do not set a guessed `Content-Length`.
PSR-7 is not a runtime dependency of this library.

Encoding, opening and read errors propagate while consuming the stream. Bytes already
sent cannot be withdrawn, so an error after transmission starts may leave an incomplete
JSON response. The library registers an internal PHP stream wrapper under the reserved
`bycerfrance-json-fragments` scheme; applications should not replace or unregister it.

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
