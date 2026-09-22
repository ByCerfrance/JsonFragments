<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\Resolver\JsonReferenceResolverInterface;
use InvalidArgumentException;
use JsonSerializable;
use Override;

final class JsonFragment implements JsonSerializable
{
    private bool $loaded = false;
    private mixed $value = null;

    public function __construct(
        private readonly JsonReference $reference,
        private readonly JsonReferenceResolverInterface $resolver,
    ) {
        if (!$resolver->supports($reference)) {
            throw new InvalidArgumentException(sprintf('Unsupported reference: %s', $reference->getRef()));
        }
    }

    public function getReference(): JsonReference
    {
        return $this->reference;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /** Resolve once. Returned objects are detached copies; failures can be retried. */
    public function resolve(): mixed
    {
        if (!$this->loaded) {
            $this->value = JsonValue::copy($this->resolver->resolve($this->reference));
            $this->loaded = true;
        }

        return JsonValue::copy($this->value);
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->resolve();
    }
}
