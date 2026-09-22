<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Resolver;

use ByCerfrance\JsonFragments\JsonReference;

interface JsonReferenceResolverInterface
{
    /**
     * Recognize references without accessing storage or the network.
     *
     * @param JsonReference $reference Reference to inspect.
     * @return bool Whether this resolver understands its envelope and identifier.
     */
    public function supports(JsonReference $reference): bool;

    /**
     * Resolve one reference, without interpreting references inside its content.
     *
     * @param JsonReference $reference Reference to resolve.
     * @return mixed Decoded JSON value.
     * @throws \Throwable If the reference is unsupported, missing or cannot be read/decoded.
     */
    public function resolve(JsonReference $reference): mixed;
}
