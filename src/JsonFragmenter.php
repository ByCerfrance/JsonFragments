<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments;

use ByCerfrance\JsonFragments\Internal\JsonPointer;
use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\Storage\JsonFragmentStoreInterface;
use Closure;
use Generator;
use InvalidArgumentException;
use stdClass;

final readonly class JsonFragmenter
{
    public function __construct(private JsonFragmentStoreInterface $store)
    {
    }

    /**
     * Externalize selected branches into immutable fragments, leaving the input untouched.
     * Missing paths are ignored. Empty pointer selects the root. Overlapping paths are rejected.
     *
     * @param mixed $json Decoded JSON; strings are scalar values, not encoded documents.
     * @param list<string> $paths JSON Pointers.
     * @return mixed New structure containing JsonReference objects.
     * @throws \Throwable If pointers, values or storage operations fail. Written objects are not rolled back.
     */
    public function externalize(mixed $json, array $paths): mixed
    {
        $pointers = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('JSON Pointers must be strings.');
            }
            $tokens = JsonPointer::parse($path);
            foreach ($pointers as $existing) {
                $length = min(count($tokens), count($existing));
                if (array_slice($tokens, 0, $length) === array_slice($existing, 0, $length)) {
                    throw new InvalidArgumentException('JSON Pointers must not overlap or repeat.');
                }
            }
            $pointers[] = $tokens;
        }

        $output = JsonValue::copy($json);
        // Validate path traversal before any storage writes.
        foreach ($pointers as $tokens) {
            JsonPointer::replace($output, $tokens, static fn(mixed $value): mixed => $value);
        }
        foreach ($pointers as $tokens) {
            $output = JsonPointer::replace($output, $tokens, function (mixed $value): JsonReference {
                $reference = $this->asReference($value);
                if (null !== $reference && $this->store->supports($reference)) {
                    return $this->store->store($reference);
                }

                return $this->store->store($this->resolve($value));
            });
        }

        return $output;
    }

    /** Create lazy fragments for supported references without reading storage. */
    public function hydrate(mixed $json): mixed
    {
        return $this->transform(JsonValue::copy($json), function (JsonReference $reference, mixed $value): mixed {
            if ($value instanceof JsonFragment) {
                return $value;
            }

            return $this->store->supports($reference) ? new JsonFragment($reference, $this->store) : $value;
        });
    }

    /** Restore reference envelopes for persistence without resolving lazy fragments. */
    public function dehydrate(mixed $json): mixed
    {
        return $this->transform(
            JsonValue::copy($json),
            fn(JsonReference $reference): JsonReference => new JsonReference(
                $reference->getRef(),
                $this->dehydrate($reference->getProperties()),
            ),
        );
    }

    /** Resolve supported references. References inside stored content are not recursively interpreted. */
    public function resolve(mixed $json): mixed
    {
        return $this->transform(JsonValue::copy($json), function (JsonReference $reference, mixed $value): mixed {
            if ($value instanceof JsonFragment) {
                return $value->resolve();
            }

            return $this->store->supports($reference)
                ? JsonValue::copy($this->store->resolve($reference))
                : $value;
        });
    }

    /**
     * Inspect every $ref occurrence, including natural references and reference sibling properties.
     *
     * @return Generator<string, JsonReference> JSON Pointer to the containing object => reference.
     */
    public function references(mixed $json): Generator
    {
        yield from $this->inspect(JsonValue::copy($json), '');
    }

    private function asReference(mixed $value): ?JsonReference
    {
        if ($value instanceof JsonFragment) {
            return $value->getReference();
        }
        if ($value instanceof JsonReference) {
            return $value;
        }
        if (is_array($value) || $value instanceof stdClass) {
            $properties = (array)$value;
            if (isset($properties['$ref']) && is_string($properties['$ref'])) {
                $ref = $properties['$ref'];
                unset($properties['$ref']);

                return new JsonReference($ref, $properties);
            }
        }

        return null;
    }

    private function transform(mixed $value, Closure $operation): mixed
    {
        $reference = $this->asReference($value);
        if (null !== $reference) {
            $result = $operation($reference, $value);
            // Replaced references are terminal: never follow a graph through stored content.
            if ($result !== $value || $value instanceof JsonFragment) {
                return $result;
            }
        }
        if ($value instanceof JsonReference) {
            return new JsonReference($value->getRef(), $this->transform($value->getProperties(), $operation));
        }
        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $key => $child) {
                if (is_array($value)) {
                    $value[$key] = $this->transform($child, $operation);
                } else {
                    $value->{(string)$key} = $this->transform($child, $operation);
                }
            }
        }

        return $value;
    }

    private function inspect(mixed $value, string $path): Generator
    {
        $reference = $this->asReference($value);
        if (null !== $reference) {
            yield $path => $reference;
            $value = $reference->getProperties();
        }
        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $key => $child) {
                yield from $this->inspect($child, JsonPointer::append($path, $key));
            }
        }
    }
}
