<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\JsonFragment;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Resolver\JsonReferenceResolverInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JsonFragment::class)]
#[UsesClass(JsonReference::class)]
#[UsesClass(JsonValue::class)]
final class JsonFragmentTest extends TestCase
{
    public function testConstructionAndReferenceAccessDoNotResolve(): void
    {
        $reference = new JsonReference('urn:fragment:1');
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->expects(self::never())->method('resolve');

        $fragment = new JsonFragment($reference, $resolver);
        self::assertSame($reference, $fragment->getReference());
        self::assertFalse($fragment->isLoaded());
    }

    #[DataProvider('values')]
    public function testValuesAreCachedIncludingFalsyValues(mixed $value): void
    {
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->expects(self::once())->method('resolve')->willReturn($value);
        $fragment = new JsonFragment(new JsonReference('urn:fragment:1'), $resolver);

        self::assertSame($value, $fragment->resolve());
        self::assertTrue($fragment->isLoaded());
        self::assertSame($value, $fragment->resolve());
        self::assertSame(json_encode($value), json_encode($fragment));
    }

    public static function values(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'empty list' => [[]];
        yield 'list' => [[1, 2, 3]];
    }

    public function testCachedObjectsCannotBeMutatedByCallerOrResolver(): void
    {
        $value = (object)['nested' => (object)['amount' => 120]];
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->expects(self::once())->method('resolve')->willReturn($value);
        $fragment = new JsonFragment(new JsonReference('urn:fragment:1'), $resolver);

        $loaded = $fragment->resolve();
        $loaded->nested->amount = 999;
        $value->nested->amount = 500;

        self::assertSame(120, $fragment->resolve()->nested->amount);
    }

    public function testFailedResolutionCanBeRetried(): void
    {
        $attempts = 0;
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->expects(self::exactly(2))->method('resolve')->willReturnCallback(
            static function () use (&$attempts): string {
                if (++$attempts === 1) {
                    throw new RuntimeException('Temporary failure');
                }

                return 'loaded';
            },
        );
        $fragment = new JsonFragment(new JsonReference('urn:fragment:1'), $resolver);

        try {
            $fragment->resolve();
            self::fail('Expected resolver failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Temporary failure', $exception->getMessage());
        }
        self::assertFalse($fragment->isLoaded());
        self::assertSame('loaded', $fragment->resolve());
        self::assertTrue($fragment->isLoaded());
    }

    public function testUnsupportedReferencesAreRejectedWithoutResolution(): void
    {
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(false);
        $resolver->expects(self::never())->method('resolve');

        $this->expectException(InvalidArgumentException::class);
        new JsonFragment(new JsonReference('https://example.org'), $resolver);
    }
}
