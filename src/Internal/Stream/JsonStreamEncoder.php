<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Internal\Stream;

use ByCerfrance\JsonFragments\JsonFragment;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Resolver\JsonReferenceResolverInterface;
use ByCerfrance\JsonFragments\Resolver\StreamingJsonReferenceResolverInterface;
use Generator;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/** @internal Produces segments, transferring ownership of each yielded resource to the consumer. */
final readonly class JsonStreamEncoder
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(private JsonReferenceResolverInterface $resolver)
    {
    }

    /** @return Generator<int, string|resource> */
    public function encode(mixed $value, int $depth = 0, bool $resolveReferences = true): Generator
    {
        if ($depth > 128) {
            throw new InvalidArgumentException('JSON nesting exceeds 128 levels or contains a cycle.');
        }

        if ($value instanceof JsonFragment) {
            if ($resolveReferences) {
                yield from $this->reference($value->getReference(), $value->getResolver(), $depth);

                return;
            }
            $value = $value->getReference();
        }
        if ($value instanceof JsonReference) {
            if ($resolveReferences && $this->resolver->supports($value)) {
                yield from $this->reference($value, $this->resolver, $depth);

                return;
            }
            $value = $value->jsonSerialize();
        } elseif ($value instanceof JsonSerializable) {
            yield from $this->encode($value->jsonSerialize(), $depth + 1, $resolveReferences);

            return;
        }

        if (is_array($value) || $value instanceof stdClass) {
            $ref = is_array($value) ? ($value['$ref'] ?? null) : ($value->{'$ref'} ?? null);
            if ($resolveReferences && is_string($ref)) {
                $properties = (array)$value;
                unset($properties['$ref']);
                $reference = new JsonReference($ref, $properties);
                if ($this->resolver->supports($reference)) {
                    yield from $this->reference($reference, $this->resolver, $depth);

                    return;
                }
            }

            $list = is_array($value) && array_is_list($value);
            yield $list ? '[' : '{';
            $first = true;
            foreach ($value as $key => $child) {
                if (!$first) {
                    yield ',';
                }
                $first = false;
                if (!$list) {
                    yield json_encode((string)$key, self::FLAGS) . ':';
                }
                yield from $this->encode($child, $depth + 1, $resolveReferences);
            }
            yield $list ? ']' : '}';

            return;
        }

        if (null !== $value && !is_scalar($value)) {
            throw new InvalidArgumentException('Expected a JSON value, stdClass or JsonSerializable.');
        }
        yield json_encode($value, self::FLAGS);
    }

    /** @return Generator<int, string|resource> */
    private function reference(
        JsonReference $reference,
        JsonReferenceResolverInterface $resolver,
        int $depth,
    ): Generator {
        if ($resolver instanceof StreamingJsonReferenceResolverInterface) {
            yield $resolver->readStream($reference);

            return;
        }

        // Resolved values are terminal: do not follow references inside stored content.
        yield from $this->encode($resolver->resolve($reference), $depth + 1, false);
    }
}
