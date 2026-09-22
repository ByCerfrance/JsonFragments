<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Internal;

use InvalidArgumentException;

/** @internal */
final class StoragePath
{
    public static function key(string $key): string
    {
        if ('' === $key || preg_match('/[\\\\\x00-\x20\x7f:?#%]/', $key)) {
            throw new InvalidArgumentException('Storage keys must be unambiguous relative paths.');
        }

        foreach (explode('/', $key) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new InvalidArgumentException('Storage keys cannot contain empty, dot or parent segments.');
            }
        }

        return $key;
    }
}
