<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Storage;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\Internal\StoragePath;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Storage\FlysystemFragmentStorage;
use InvalidArgumentException;
use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlysystemFragmentStorage::class)]
#[UsesClass(JsonReference::class)]
#[UsesClass(JsonValue::class)]
#[UsesClass(StoragePath::class)]
final class FlysystemFragmentStorageTest extends TestCase
{
    public function testStoreAndResolvePreserveObjectListAndScalarTypes(): void
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $storage = new FlysystemFragmentStorage($filesystem);
        $input = (object)[
            'object' => (object)[],
            'list' => [],
            'float' => 1.0,
            'null' => null,
            'false' => false,
            'text' => 'Été',
        ];

        $reference = $storage->store($input);
        self::assertMatchesRegularExpression('~^jsonfragment://[a-f0-9]{32}\.json$~', $reference->getRef());
        $resolved = $storage->resolve($reference);
        self::assertEquals($input, $resolved);
        self::assertIsFloat($resolved->float);
        self::assertInstanceOf(\stdClass::class, $resolved->object);
        self::assertSame([], $resolved->list);
    }

    public function testExistingReferenceIsReusedWithoutIo(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('read');
        $filesystem->expects(self::never())->method('readStream');
        $filesystem->expects(self::never())->method('write');
        $filesystem->expects(self::never())->method('fileExists');
        $storage = new FlysystemFragmentStorage($filesystem);
        $reference = new JsonReference('jsonfragment://existing.json');

        self::assertSame($reference, $storage->store($reference));
    }

    public function testNaturalReferenceIsStoredAsDataWithoutFetchingItsTarget(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('read');
        $filesystem->expects(self::never())->method('readStream');
        $filesystem->expects(self::once())->method('write')->with(
            self::matchesRegularExpression('~^[a-f0-9]{32}\.json$~'),
            '{"$ref":"https://example.org/schema","description":"Invoice"}',
        );
        $storage = new FlysystemFragmentStorage($filesystem);
        $reference = $storage->store(new JsonReference('https://example.org/schema', ['description' => 'Invoice']));

        self::assertTrue($storage->supports($reference));
    }

    public function testSupportsOnlyConfiguredSchemeAndUnannotatedReferencesWithoutIo(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('read');
        $filesystem->expects(self::never())->method('readStream');
        $storage = new FlysystemFragmentStorage($filesystem, referencePrefix: 'custom://fragments/');

        self::assertTrue($storage->supports(new JsonReference('custom://fragments/a.json')));
        self::assertFalse($storage->supports(new JsonReference('jsonfragment://a.json')));
        self::assertFalse($storage->supports(new JsonReference('https://example.org/a.json')));
        self::assertFalse($storage->supports(new JsonReference('custom://fragments/a.json', ['extra' => true])));
    }

    #[DataProvider('invalidPrefixes')]
    public function testInvalidReferencePrefixIsRejected(string $prefix): void
    {
        $filesystem = $this->createStub(FilesystemOperator::class);
        $this->expectException(InvalidArgumentException::class);
        new FlysystemFragmentStorage($filesystem, referencePrefix: $prefix);
    }

    public static function invalidPrefixes(): iterable
    {
        foreach (['', 'relative/', '1invalid://', 'custom://no-trailing-slash',
            'custom://space here/', 'custom://query?/', 'custom://fragment#/', 'custom://encoded%2F/'] as $prefix) {
            yield $prefix => [$prefix];
        }
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidReferenceCannotReachFilesystem(string $ref): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('read');
        $filesystem->expects(self::never())->method('readStream');
        $storage = new FlysystemFragmentStorage($filesystem);

        $this->expectException(InvalidArgumentException::class);
        $storage->resolve(new JsonReference($ref));
    }

    public static function invalidReferences(): iterable
    {
        yield 'unsupported scheme' => ['https://example.org/file'];
        yield 'parent segment' => ['jsonfragment://owner/../file'];
        yield 'absolute path' => ['jsonfragment:///file'];
        yield 'encoded traversal' => ['jsonfragment://%2e%2e/file'];
        yield 'empty key' => ['jsonfragment://'];
        yield 'backslash' => ['jsonfragment://owner\\file'];
    }

    public function testMissingFileErrorIsPropagated(): void
    {
        $storage = new FlysystemFragmentStorage(new Filesystem(new InMemoryFilesystemAdapter()));
        $this->expectException(UnableToReadFile::class);
        $storage->resolve(new JsonReference('jsonfragment://missing.json'));
    }

    public function testInvalidStoredJsonRaisesJsonException(): void
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $filesystem->write('broken.json', '{not json');
        $storage = new FlysystemFragmentStorage($filesystem);

        $this->expectException(JsonException::class);
        $storage->resolve(new JsonReference('jsonfragment://broken.json'));
    }

    public function testWriteFailureIsPropagatedWithoutReturningReference(): void
    {
        $failure = UnableToWriteFile::atLocation('owner/file.json', 'Storage unavailable');
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('write')->willThrowException($failure);
        $storage = new FlysystemFragmentStorage($filesystem);

        $this->expectExceptionObject($failure);
        $storage->store(['amount' => 120]);
    }

    public function testInvalidContentFailsBeforeWriting(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('write');
        $storage = new FlysystemFragmentStorage($filesystem);

        $this->expectException(JsonException::class);
        $storage->store(['invalid' => INF]);
    }

    public function testReadStreamReturnsNativeResourceWithoutReadingOrDecoding(): void
    {
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, '[1,2,3]');
        rewind($resource);
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('read');
        $filesystem->expects(self::once())->method('readStream')->with('data.json')->willReturn($resource);
        $storage = new FlysystemFragmentStorage($filesystem);

        $stream = $storage->readStream(new JsonReference('jsonfragment://data.json'));
        try {
            self::assertSame($resource, $stream);
            self::assertSame(0, ftell($stream));
            self::assertSame('[1,2,3]', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('streamContents')]
    public function testResolveClosesResourceOnSuccessOrInvalidJson(string $content, bool $valid): void
    {
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, $content);
        rewind($resource);
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn($resource);
        $storage = new FlysystemFragmentStorage($filesystem);

        try {
            if (!$valid) {
                $this->expectException(JsonException::class);
            }
            self::assertSame([1, 2], $storage->resolve(new JsonReference('jsonfragment://data.json')));
        } finally {
            self::assertFalse(is_resource($resource));
        }
    }

    public static function streamContents(): iterable
    {
        yield 'valid' => ['[1,2]', true];
        yield 'invalid' => ['{broken', false];
    }
}
