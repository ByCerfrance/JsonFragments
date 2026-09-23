<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Internal\Stream;

use ByCerfrance\JsonFragments\Internal\Stream\SegmentReader;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SegmentReader::class)]
final class SegmentReaderTest extends TestCase
{
    public function testBoundariesAndEmptySegmentsNeverExceedRequestedLength(): void
    {
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, 'cde');
        rewind($resource);
        $producer = static function () use ($resource): Generator {
            yield '';
            yield 'ab';
            yield $resource;
            yield '';
            yield 'fg';
        };
        $reader = new SegmentReader($producer());

        self::assertSame('', $reader->read(0));
        self::assertSame('abc', $reader->read(3));
        self::assertSame('def', $reader->read(3));
        self::assertFalse(is_resource($resource));
        self::assertSame('g', $reader->read(3));
        self::assertSame('', $reader->read(3));
        self::assertSame(7, $reader->tell());
        self::assertTrue($reader->eof());
        $reader->close();
        $reader->close();
    }

    public function testClosingUnstartedProducerDoesNotExecuteIt(): void
    {
        $started = false;
        $producer = static function () use (&$started): Generator {
            $started = true;
            yield 'data';
        };
        $reader = new SegmentReader($producer());
        $reader->close();

        self::assertFalse($started);
        self::assertTrue($reader->eof());
        self::assertSame('', $reader->read(1));
    }

    public function testDestroyingReaderClosesCurrentResource(): void
    {
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, str_repeat('x', 16384));
        rewind($resource);
        $producer = static function () use ($resource): Generator {
            yield $resource;
        };
        $reader = new SegmentReader($producer());
        self::assertSame('x', $reader->read(1));
        unset($reader);

        self::assertFalse(is_resource($resource));
    }

    public function testInvalidSegmentFailsAndReleasesProducer(): void
    {
        $released = false;
        $producer = static function () use (&$released): Generator {
            try {
                yield 123;
            } finally {
                $released = true;
            }
        };
        $reader = new SegmentReader($producer());

        try {
            $this->expectException(RuntimeException::class);
            $reader->read(1);
        } finally {
            self::assertTrue($released);
            self::assertTrue($reader->eof());
        }
    }

    public function testWriteOnlySourceIsRejectedAndClosed(): void
    {
        $resource = fopen('php://output', 'wb');
        $producer = static function () use ($resource): Generator {
            yield $resource;
        };
        $reader = new SegmentReader($producer());

        try {
            $this->expectExceptionMessage('not readable');
            $reader->read(1);
        } finally {
            self::assertFalse(is_resource($resource));
        }
    }
}
