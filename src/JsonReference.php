<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use InvalidArgumentException;
use JsonSerializable;
use Override;
use stdClass;

final readonly class JsonReference implements JsonSerializable
{
    private array $properties;

    /** @param array<string, mixed> $properties Sibling properties, excluding the reserved $ref key. */
    public function __construct(private string $ref, array $properties = [])
    {
        if (array_key_exists('$ref', $properties)) {
            throw new InvalidArgumentException('Reference properties cannot override $ref.');
        }
        json_encode($ref, JSON_THROW_ON_ERROR);
        $this->properties = JsonValue::copy($properties);
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getScheme(): ?string
    {
        return preg_match('/^([a-z][a-z0-9+.-]*):/i', $this->ref, $matches)
            ? strtolower($matches[1])
            : null;
    }

    /** @return array<string, mixed> */
    public function getProperties(): array
    {
        return JsonValue::copy($this->properties);
    }

    #[Override]
    public function jsonSerialize(): stdClass
    {
        return (object)(['$ref' => $this->ref] + $this->getProperties());
    }
}
