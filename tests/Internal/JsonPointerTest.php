<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Internal;

use ByCerfrance\JsonFragments\Internal\JsonPointer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonPointer::class)]
final class JsonPointerTest extends TestCase
{
    #[DataProvider('pointers')]
    public function testPointerTokensFollowRfc6901(string $pointer, array $tokens): void
    {
        self::assertSame($tokens, JsonPointer::parse($pointer));
        $rebuilt = '';
        foreach ($tokens as $token) {
            $rebuilt = JsonPointer::append($rebuilt, $token);
        }
        self::assertSame($pointer, $rebuilt);
    }

    public static function pointers(): iterable
    {
        yield 'root' => ['', []];
        yield 'empty key' => ['/', ['']];
        yield 'slash and tilde' => ['/a~1b/~0key', ['a/b', '~key']];
        yield 'escape order' => ['/~01', ['~1']];
        yield 'unicode' => ['/été/0', ['été', '0']];
    }

    #[DataProvider('invalidPointers')]
    public function testInvalidPointersAreRejected(string $pointer): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonPointer::parse($pointer);
    }

    public static function invalidPointers(): iterable
    {
        yield ['properties'];
        yield ['#/properties'];
        yield ['/invalid~'];
        yield ['/invalid~2'];
    }

    public function testMissingPathsDoNotInvokeReplacementButNullDoes(): void
    {
        $calls = 0;
        $replace = static function (mixed $value) use (&$calls): string {
            ++$calls;

            return 'replaced';
        };
        $input = (object)['present' => null];
        self::assertEquals($input, JsonPointer::replace($input, ['missing'], $replace));
        self::assertSame(0, $calls);
        self::assertSame('replaced', JsonPointer::replace($input, ['present'], $replace)->present);
        self::assertSame(1, $calls);
    }

    public function testLeadingZeroIsAnObjectKeyButNotAListIndex(): void
    {
        $object = (object)['01' => 'value'];
        self::assertSame('changed', JsonPointer::replace($object, ['01'], static fn() => 'changed')->{'01'});

        $this->expectException(InvalidArgumentException::class);
        JsonPointer::replace(['zero', 'one'], ['01'], static fn() => 'changed');
    }
}
