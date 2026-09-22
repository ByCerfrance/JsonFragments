<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Internal;

use ByCerfrance\JsonFragments\JsonFragment;
use ByCerfrance\JsonFragments\JsonReference;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/** @internal */
final class JsonValue
{
    /** Copy JSON values without resolving fragments. Reject cycles and unsupported values. */
    public static function copy(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 128) {
            throw new InvalidArgumentException('JSON nesting exceeds 128 levels or contains a cycle.');
        }

        if ($value instanceof JsonReference || $value instanceof JsonFragment) {
            return $value;
        }

        if ($value instanceof JsonSerializable) {
            return self::copy($value->jsonSerialize(), $depth + 1);
        }

        if (is_array($value) || $value instanceof stdClass) {
            $copy = is_array($value) ? [] : new stdClass();
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    json_encode($key, JSON_THROW_ON_ERROR);
                }
                $child = self::copy($child, $depth + 1);
                if (is_array($copy)) {
                    $copy[$key] = $child;
                } else {
                    $copy->{(string)$key} = $child;
                }
            }

            return $copy;
        }

        if (null === $value || is_scalar($value)) {
            json_encode($value, JSON_THROW_ON_ERROR);

            return $value;
        }

        throw new InvalidArgumentException('Expected a JSON value, stdClass or JsonSerializable.');
    }

    /** Stable JSON encoding: sort object keys, preserve list order and floating-point values. */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::canonicalize(self::copy($value)),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof JsonFragment) {
            throw new InvalidArgumentException('Resolve fragments explicitly before writing their content.');
        }

        if ($value instanceof JsonReference) {
            $value = $value->jsonSerialize();
        }

        if (!is_array($value) && !$value instanceof stdClass) {
            return $value;
        }

        $isList = is_array($value) && array_is_list($value);
        $values = (array)$value;
        if (!$isList) {
            ksort($values, SORT_STRING);
        }
        foreach ($values as $key => $child) {
            $values[$key] = self::canonicalize($child);
        }

        return $isList ? $values : (object)$values;
    }
}
