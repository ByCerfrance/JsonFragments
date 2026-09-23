<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Storage;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\Internal\StoragePath;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Resolver\StreamingJsonReferenceResolverInterface;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;
use Override;
use RuntimeException;

final readonly class FlysystemFragmentStorage implements
    JsonFragmentStoreInterface,
    StreamingJsonReferenceResolverInterface
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private string $referencePrefix = 'jsonfragment://',
    ) {
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~', $referencePrefix)
            || !str_ends_with($referencePrefix, '/')
            || preg_match('/[\\\\\x00-\x20\x7f?#%]/', $referencePrefix)) {
            throw new InvalidArgumentException('The reference prefix must start with a scheme and end with /.');
        }
    }

    #[Override]
    public function store(mixed $value): JsonReference
    {
        if ($value instanceof JsonReference && $this->supports($value)) {
            $this->key($value);

            return $value;
        }

        $json = JsonValue::encode($value);
        // Identity belongs to this fragment, not to the document or its content.
        $key = bin2hex(random_bytes(16)) . '.json';
        $this->filesystem->write($key, $json);

        return new JsonReference($this->referencePrefix . $key);
    }

    #[Override]
    public function supports(JsonReference $reference): bool
    {
        return [] === $reference->getProperties()
            && str_starts_with($reference->getRef(), $this->referencePrefix);
    }

    #[Override]
    public function resolve(JsonReference $reference): mixed
    {
        $stream = $this->readStream($reference);

        try {
            $json = stream_get_contents($stream);
            if (false === $json) {
                throw new RuntimeException('Unable to read the JSON fragment.');
            }

            return json_decode($json, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    #[Override]
    public function readStream(JsonReference $reference)
    {
        return $this->filesystem->readStream($this->key($reference));
    }

    private function key(JsonReference $reference): string
    {
        if (!$this->supports($reference)) {
            throw new InvalidArgumentException(sprintf('Unsupported reference: %s', $reference->getRef()));
        }

        return StoragePath::key(substr($reference->getRef(), strlen($this->referencePrefix)));
    }
}
