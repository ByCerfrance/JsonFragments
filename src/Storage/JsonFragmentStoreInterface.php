<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Storage;

use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Resolver\JsonReferenceResolverInterface;

interface JsonFragmentStoreInterface extends JsonReferenceResolverInterface
{
    /**
     * Store content or reuse a supported reference in this store's context without I/O.
     * Callers must supply the same storage context when reusing a reference.
     * To copy between contexts, resolve with the source store and store the resulting value in the destination.
     * Unsupported references are JSON content, not instructions to resolve a resource.
     *
     * @param mixed $value Decoded JSON value or an existing JsonReference.
     * @return JsonReference New reference after a successful write, or the existing reference without existence checks.
     * @throws \Throwable If the reference, content or storage operation is invalid.
     */
    public function store(mixed $value): JsonReference;
}
