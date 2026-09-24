<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Internal;

use InvalidArgumentException;
use stdClass;

/** @internal */
final class JsonPointerPattern
{
    /**
     * Expand whole-segment wildcards into exact JSON Pointers on a normalized JSON value.
     * Only inline arrays and stdClass objects are traversed; references remain terminal.
     * Missing branches are ignored, while existing null values are selected.
     *
     * @return list<string>
     */
    public static function expand(mixed $value, string $pattern): array
    {
        return self::select($value, JsonPointer::parse($pattern), '');
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private static function select(mixed $value, array $tokens, string $path): array
    {
        if ([] === $tokens) {
            return [$path];
        }
        if (!is_array($value) && !$value instanceof stdClass) {
            return [];
        }

        $key = array_shift($tokens);
        if ('*' === $key) {
            $paths = [];
            foreach ($value as $childKey => $child) {
                array_push($paths, ...self::select($child, $tokens, JsonPointer::append($path, $childKey)));
            }

            return $paths;
        }

        if (is_array($value)) {
            if ([] === $value) {
                return [];
            }
            if (array_is_list($value) && !preg_match('/^(0|[1-9][0-9]*)$/', $key)) {
                throw new InvalidArgumentException(sprintf('Invalid JSON array index: %s', $key));
            }
            if (array_key_exists($key, $value)) {
                return self::select($value[$key], $tokens, JsonPointer::append($path, $key));
            }
        } elseif (property_exists($value, $key)) {
            return self::select($value->{$key}, $tokens, JsonPointer::append($path, $key));
        }

        return [];
    }
}
