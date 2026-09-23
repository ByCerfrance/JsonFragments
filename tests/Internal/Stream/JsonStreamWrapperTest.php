<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Internal\Stream;

use Berlioz\Http\Message\HttpFactory;
use ByCerfrance\JsonFragments\Internal\Stream\JsonStreamWrapper;
use ByCerfrance\JsonFragments\Internal\Stream\SegmentReader;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JsonStreamWrapper::class)]
#[UsesClass(SegmentReader::class)]
final class JsonStreamWrapperTest extends TestCase
{
    public function testPsr17FactoryPreservesNonSeekableUnknownSizeAndReadOnlySemantics(): void
    {
        $started = false;
        $producer = static function () use (&$started): Generator {
            $started = true;
            yield '{"values":';
            yield '[1,2]}';
        };
        $resource = JsonStreamWrapper::open($producer());
        $body = (new HttpFactory())->createStreamFromResource($resource);

        try {
            self::assertFalse($started);
            self::assertTrue($body->isReadable());
            self::assertFalse($body->isWritable());
            self::assertFalse($body->isSeekable());
            self::assertNull($body->getSize());
            self::assertSame('{"', $body->read(2));
            self::assertSame(2, $body->tell());
            self::assertSame('values":[1,2]}', $body->getContents());
            self::assertTrue($body->eof());
        } finally {
            $body->close();
        }
        self::assertFalse(is_resource($resource));
    }

    public function testPsrBodyCloseReleasesAnActiveSource(): void
    {
        $source = fopen('php://memory', 'w+b');
        fwrite($source, str_repeat('x', 32768));
        rewind($source);
        $producer = static function () use ($source): Generator {
            yield '"';
            yield $source;
            yield '"';
        };
        $body = (new HttpFactory())->createStreamFromResource(JsonStreamWrapper::open($producer()));
        self::assertSame('"x', $body->read(2));
        $body->close();
        self::assertFalse(is_resource($source));
    }

    public function testPsrBodyRejectsSeekingWithoutStartingProducer(): void
    {
        $started = false;
        $producer = static function () use (&$started): Generator {
            $started = true;
            yield '[]';
        };
        $body = (new HttpFactory())->createStreamFromResource(JsonStreamWrapper::open($producer()));
        try {
            $this->expectException(RuntimeException::class);
            $body->rewind();
        } finally {
            self::assertFalse($started);
            $body->close();
        }
    }

    public function testCastingPsrBodyToStringWorksButMaterializesTheOutput(): void
    {
        $producer = static function (): Generator {
            yield '[1,';
            yield '2]';
        };
        $body = (new HttpFactory())->createStreamFromResource(JsonStreamWrapper::open($producer()));
        try {
            self::assertSame('[1,2]', (string)$body);
        } finally {
            $body->close();
        }
    }
}
