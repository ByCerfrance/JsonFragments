<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Internal;

use Closure;
use InvalidArgumentException;
use stdClass;

/** @internal */
final class JsonPointer
{
    /** @return list<string> */
    public static function parse(string $path): array
    {
        if ('' === $path) {
            return [];
        }
        if (!str_starts_with($path, '/') || preg_match('/~(?![01])/', $path)) {
            throw new InvalidArgumentException(sprintf('Invalid JSON Pointer: %s', $path));
        }

        return array_map(
            static fn(string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            explode('/', substr($path, 1)),
        );
    }

    public static function append(string $path, string|int $key): string
    {
        return $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key);
    }

    /** @param list<string> $tokens */
    public static function replace(mixed $value, array $tokens, Closure $replacement): mixed
    {
        if ([] === $tokens) {
            return $replacement($value);
        }

        $key = array_shift($tokens);
        if (is_array($value)) {
            if (array_is_list($value) && !preg_match('/^(0|[1-9][0-9]*)$/', $key)) {
                throw new InvalidArgumentException(sprintf('Invalid JSON array index: %s', $key));
            }
            if (array_key_exists($key, $value)) {
                $value[$key] = self::replace($value[$key], $tokens, $replacement);
            }
        } elseif ($value instanceof stdClass && property_exists($value, $key)) {
            $value->{$key} = self::replace($value->{$key}, $tokens, $replacement);
        }

        return $value;
    }
}
