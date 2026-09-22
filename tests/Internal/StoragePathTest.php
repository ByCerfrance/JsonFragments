<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Internal;

use ByCerfrance\JsonFragments\Internal\StoragePath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoragePath::class)]
final class StoragePathTest extends TestCase
{
    public function testRelativeUnicodeKeysArePreserved(): void
    {
        self::assertSame('résultats/été.json', StoragePath::key('résultats/été.json'));
    }

    #[DataProvider('invalidKeys')]
    public function testAmbiguousOrNonRelativeKeysAreRejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        StoragePath::key($key);
    }

    public static function invalidKeys(): iterable
    {
        foreach (['', '.', '..', 'a/./b', 'a/../b', '/a', 'a/', 'a//b', 'a\\b',
            'a?b', 'a#b', 'a%b', 'a:b', "a\0b", "a\nb", 'a b'] as $key) {
            yield [$key];
        }
    }
}
