<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\Internal\StoragePath;
use ByCerfrance\JsonFragments\Internal\Stream\JsonStreamEncoder;
use ByCerfrance\JsonFragments\Internal\Stream\JsonStreamWrapper;
use ByCerfrance\JsonFragments\Internal\Stream\SegmentReader;
use ByCerfrance\JsonFragments\JsonFragment;
use ByCerfrance\JsonFragments\JsonFragmenter;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Storage\FlysystemFragmentStorage;
use ByCerfrance\JsonFragments\Storage\JsonFragmentStoreInterface;
use ByCerfrance\JsonFragments\Tests\Fixture\StreamingStoreInterface;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JsonFragmenter::class)]
#[UsesClass(JsonStreamEncoder::class)]
#[UsesClass(JsonStreamWrapper::class)]
#[UsesClass(SegmentReader::class)]
#[UsesClass(JsonReference::class)]
#[UsesClass(JsonFragment::class)]
#[UsesClass(JsonValue::class)]
#[UsesClass(FlysystemFragmentStorage::class)]
#[UsesClass(StoragePath::class)]
final class JsonFragmenterStreamTest extends TestCase
{
    /** @return resource */
    private function resource(string $json)
    {
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, $json);
        rewind($resource);

        return $resource;
    }

    public function testOpeningAndClosingWithoutReadingDoesNotResolveOrSerialize(): void
    {
        $store = $this->createMock(StreamingStoreInterface::class);
        $store->expects(self::never())->method('supports');
        $store->expects(self::never())->method('readStream');
        $store->expects(self::never())->method('resolve');
        $value = $this->createMock(JsonSerializable::class);
        $value->expects(self::never())->method('jsonSerialize');

        $stream = (new JsonFragmenter($store))->stream($value);
        self::assertIsResource($stream);
        self::assertSame('stream', get_resource_type($stream));
        self::assertSame(0, ftell($stream));
        self::assertFalse(feof($stream));
        self::assertFalse(fstat($stream));
        self::assertFalse(stream_get_meta_data($stream)['seekable']);
        fclose($stream);
    }

    public function testNativeFragmentBytesAreForwardedWithoutDecoding(): void
    {
        // Whitespace and exponent spelling must survive: this is a raw stream, not a JSON round-trip.
        $raw = "[ 1e2, {\"empty\": {}, \"value\": null} ]";
        $resource = $this->resource($raw);
        $store = $this->createMock(StreamingStoreInterface::class);
        $store->method('supports')->willReturn(true);
        $store->expects(self::never())->method('resolve');
        $store->expects(self::once())->method('readStream')->willReturn($resource);
        $stream = (new JsonFragmenter($store))->stream([
            'title' => 'Observations',
            'samples' => ['$ref' => 'jsonfragment://samples.json'],
        ]);

        try {
            self::assertSame('{"title":"Observations","samples":' . $raw . '}', stream_get_contents($stream));
            self::assertTrue(feof($stream));
            self::assertFalse(is_resource($resource));
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('inlineValues')]
    public function testInlineJsonTypesAndEscaping(mixed $value): void
    {
        $store = $this->createStub(JsonFragmentStoreInterface::class);
        $store->method('supports')->willReturn(false);
        $stream = (new JsonFragmenter($store))->stream($value);

        try {
            $expected = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            $output = '';
            while (!feof($stream)) {
                $output .= fread($stream, 3);
            }
            self::assertSame($expected, $output);
            self::assertSame(strlen($expected), ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    public static function inlineValues(): iterable
    {
        yield [null];
        yield [false];
        yield [0];
        yield [1.0];
        yield [''];
        yield ["Été \"quoted\" \\ \n\t"];
        yield [[]];
        yield [(object)[]];
        yield [[1, false, null, (object)[]]];
        yield [(object)['0' => 'object, not list', 'a/b~' => ['x' => 1]]];
    }

    public function testFallbackResolvesOnceAndDoesNotFollowReferencesInsideContent(): void
    {
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->method('supports')->willReturn(true);
        $store->expects(self::once())->method('resolve')->willReturn((object)['$ref' => 'urn:terminal']);
        $stream = (new JsonFragmenter($store))->stream(new JsonReference('urn:source'));

        try {
            self::assertSame('{"$ref":"urn:terminal"}', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testNaturalReferencesKeepSiblingsAndNestedSupportedReferencesAreResolved(): void
    {
        $resource = $this->resource('42');
        $store = $this->createMock(StreamingStoreInterface::class);
        $store->method('supports')->willReturnCallback(
            static fn(JsonReference $ref): bool => 'urn' === $ref->getScheme(),
        );
        $store->expects(self::once())->method('readStream')->willReturn($resource);
        $store->expects(self::never())->method('resolve');
        $stream = (new JsonFragmenter($store))->stream(new JsonReference('https://example.org/schema', [
            'description' => 'Dataset',
            'nested' => new JsonReference('urn:sample'),
        ]));

        try {
            self::assertSame(
                '{"$ref":"https://example.org/schema","description":"Dataset","nested":42}',
                stream_get_contents($stream),
            );
        } finally {
            fclose($stream);
        }
    }

    public function testLazyFragmentUsesBoundResolverWithoutPopulatingValueCache(): void
    {
        $resource = $this->resource('[1,2]');
        $source = $this->createMock(StreamingStoreInterface::class);
        $source->method('supports')->willReturn(true);
        $source->expects(self::once())->method('readStream')->willReturn($resource);
        $source->expects(self::never())->method('resolve');
        $fragment = new JsonFragment(new JsonReference('urn:source'), $source);
        $other = $this->createMock(JsonFragmentStoreInterface::class);
        $other->expects(self::never())->method('resolve');
        $stream = (new JsonFragmenter($other))->stream($fragment);

        try {
            self::assertSame('[1,2]', stream_get_contents($stream));
            self::assertFalse($fragment->isLoaded());
        } finally {
            fclose($stream);
        }
    }

    public function testPartialReadIsBoundedAndCloseReleasesOnlyOpenedFragment(): void
    {
        $raw = '"' . str_repeat('x', 1024 * 1024) . '"';
        $resource = $this->resource($raw);
        $store = $this->createMock(StreamingStoreInterface::class);
        $store->method('supports')->willReturn(true);
        $store->expects(self::once())->method('readStream')->with(self::callback(
            static fn(JsonReference $ref): bool => 'urn:first' === $ref->getRef(),
        ))->willReturn($resource);
        $store->expects(self::never())->method('resolve');
        $stream = (new JsonFragmenter($store))->stream([
            new JsonReference('urn:first'), new JsonReference('urn:second'),
        ]);

        self::assertSame('["' . str_repeat('x', 30), fread($stream, 32));
        self::assertGreaterThan(0, ftell($resource));
        self::assertLessThanOrEqual(8192, ftell($resource));
        fclose($stream);
        self::assertFalse(is_resource($resource));
    }

    public function testPrefixedFlysystemStreamOpensLazilyAndClosesAfterPartialRead(): void
    {
        $resource = $this->resource('"' . str_repeat('x', 1024 * 1024) . '"');
        $opened = false;
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('read');
        $filesystem->expects(self::once())->method('readStream')
            ->with('results://document-a/first.json')
            ->willReturnCallback(static function () use (&$opened, $resource) {
                $opened = true;

                return $resource;
            });
        $storage = new FlysystemFragmentStorage($filesystem, storagePrefix: 'results://document-a');
        self::assertFalse($opened);
        $fragmenter = new JsonFragmenter($storage);
        $data = [
            new JsonReference('jsonfragment://first.json'),
            new JsonReference('jsonfragment://second.json'),
        ];

        $unread = $fragmenter->stream($data);
        fclose($unread);
        self::assertFalse($opened);

        $stream = $fragmenter->stream($data);
        try {
            self::assertFalse($opened);
            self::assertSame('["' . str_repeat('x', 30), fread($stream, 32));
            self::assertTrue($opened);
            self::assertGreaterThan(0, ftell($resource));
            self::assertLessThanOrEqual(8192, ftell($resource));
        } finally {
            fclose($stream);
        }
        self::assertFalse(is_resource($resource));
    }

    public function testNativeSourceIsConsumedFromItsCurrentPosition(): void
    {
        $resource = $this->resource('skip[1,2]');
        fseek($resource, 4);
        $store = $this->createStub(StreamingStoreInterface::class);
        $store->method('supports')->willReturn(true);
        $store->method('readStream')->willReturn($resource);
        $stream = (new JsonFragmenter($store))->stream(new JsonReference('urn:data'));

        try {
            self::assertSame('[1,2]', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
        self::assertFalse(is_resource($resource));
    }

    public function testIndependentStreamsCanBeInterleavedAndCopiedToAnotherResource(): void
    {
        $fragmenter = new JsonFragmenter($this->createStub(JsonFragmentStoreInterface::class));
        $first = $fragmenter->stream(['a' => 1]);
        $second = $fragmenter->stream([2, 3]);
        $destination = fopen('php://memory', 'w+b');

        try {
            self::assertSame('{', fread($first, 1));
            self::assertSame('[2', fread($second, 2));
            self::assertSame(6, stream_copy_to_stream($first, $destination));
            rewind($destination);
            self::assertSame('"a":1}', stream_get_contents($destination));
            self::assertSame(',3]', stream_get_contents($second));
        } finally {
            fclose($first);
            fclose($second);
            fclose($destination);
        }
    }

    public function testSerializationIsDeferredUntilItsBranchIsRead(): void
    {
        $value = $this->createMock(JsonSerializable::class);
        $value->expects(self::never())->method('jsonSerialize');
        $fragmenter = new JsonFragmenter($this->createStub(JsonFragmentStoreInterface::class));
        $stream = $fragmenter->stream(['padding' => str_repeat('x', 32768), 'later' => $value]);

        fread($stream, 16);
        fclose($stream);
    }

    public function testInvalidStreamResolverReturnFailsOnRead(): void
    {
        $store = $this->createStub(StreamingStoreInterface::class);
        $store->method('supports')->willReturn(true);
        $store->method('readStream')->willReturn(123);
        $stream = (new JsonFragmenter($store))->stream(new JsonReference('urn:broken'));

        try {
            $this->expectException(RuntimeException::class);
            stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testResolverFailureClosesPreviouslyConsumedResource(): void
    {
        $resource = $this->resource('[1]');
        $calls = 0;
        $store = $this->createStub(StreamingStoreInterface::class);
        $store->method('supports')->willReturn(true);
        $store->method('readStream')->willReturnCallback(static function () use (&$calls, $resource) {
            if (++$calls === 1) {
                return $resource;
            }
            throw new RuntimeException('Storage unavailable');
        });
        $stream = (new JsonFragmenter($store))->stream([new JsonReference('urn:a'), new JsonReference('urn:b')]);

        try {
            $this->expectExceptionMessage('Storage unavailable');
            stream_get_contents($stream);
        } finally {
            self::assertFalse(is_resource($resource));
            fclose($stream);
        }
    }

    public function testCyclicInputFailsDuringReadInsteadOfRecursingIndefinitely(): void
    {
        $data = (object)[];
        $data->self = $data;
        $stream = (new JsonFragmenter($this->createStub(JsonFragmentStoreInterface::class)))->stream($data);

        try {
            $this->expectException(InvalidArgumentException::class);
            stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testInvalidInlineEncodingFailsDuringRead(): void
    {
        $stream = (new JsonFragmenter($this->createStub(JsonFragmentStoreInterface::class)))->stream(INF);
        try {
            $this->expectException(JsonException::class);
            stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
