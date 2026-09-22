<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests;

use ByCerfrance\JsonFragments\Internal\JsonPointer;
use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\Internal\StoragePath;
use ByCerfrance\JsonFragments\JsonFragment;
use ByCerfrance\JsonFragments\JsonFragmenter;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Storage\FlysystemFragmentStorage;
use ByCerfrance\JsonFragments\Storage\JsonFragmentStoreInterface;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonFragmenter::class)]
#[UsesClass(JsonFragment::class)]
#[UsesClass(JsonReference::class)]
#[UsesClass(JsonPointer::class)]
#[UsesClass(JsonValue::class)]
#[UsesClass(StoragePath::class)]
#[UsesClass(FlysystemFragmentStorage::class)]
final class JsonFragmenterTest extends TestCase
{
    private function fragmenter(): JsonFragmenter
    {
        return new JsonFragmenter(new FlysystemFragmentStorage(
            new Filesystem(new InMemoryFilesystemAdapter()),
        ));
    }

    public function testRoundTripPreservesJsonShapesAndInput(): void
    {
        $input = json_decode('{"model":"invoice:1","properties":{"empty":{},"list":[],"value":null}}');
        $before = json_encode($input);
        $fragmenter = $this->fragmenter();

        $compressed = $fragmenter->externalize($input, ['/properties']);

        self::assertSame($before, json_encode($input));
        self::assertInstanceOf(JsonReference::class, $compressed->properties);
        self::assertStringStartsWith('jsonfragment://', $compressed->properties->getRef());
        $restored = $fragmenter->resolve(json_decode(json_encode($compressed)));
        self::assertEquals($input, $restored);
    }

    public function testHydrationInspectionAndPersistenceNeverRead(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('read');
        $storage->expects(self::never())->method('write');
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));
        $input = ['properties' => ['$ref' => 'jsonfragment://a.json']];

        $hydrated = $fragmenter->hydrate($input);
        self::assertInstanceOf(JsonFragment::class, $hydrated['properties']);
        self::assertFalse($hydrated['properties']->isLoaded());
        $references = iterator_to_array($fragmenter->references($hydrated));
        self::assertSame('jsonfragment', $references['/properties']->getScheme());
        self::assertSame(json_encode($input), json_encode($fragmenter->dehydrate($hydrated)));
        self::assertSame(
            json_encode($input),
            json_encode($fragmenter->externalize($hydrated, ['/properties'])),
        );
    }

    public function testLazyNullIsLoadedOnce(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('read')->with('null.json')->willReturn('null');
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));
        $fragment = $fragmenter->hydrate(['$ref' => 'jsonfragment://null.json']);

        self::assertNull($fragment->resolve());
        self::assertTrue($fragment->isLoaded());
        self::assertSame('null', json_encode($fragment));
        self::assertSame('{"$ref":"jsonfragment:\/\/null.json"}', json_encode($fragmenter->dehydrate($fragment)));
    }

    public function testNaturalReferencesAndSiblingPropertiesArePreserved(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('read');
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));
        $input = [
            'web' => ['$ref' => 'https://example.org/schema.json', 'description' => 'Invoice'],
            'local' => ['$ref' => '#/definitions/address'],
            'annotated' => ['$ref' => 'jsonfragment://a.json', 'description' => 'Not our envelope'],
        ];

        self::assertSame($input, $fragmenter->resolve($input));
        self::assertSame($input, $fragmenter->hydrate($input));
        $references = iterator_to_array($fragmenter->references($input));
        self::assertCount(3, $references);
        self::assertSame('https', $references['/web']->getScheme());
        self::assertNull($references['/local']->getScheme());
        self::assertSame(['description' => 'Invoice'], $references['/web']->getProperties());
    }

    public function testPointersHandleEscapingNullAndMissingPaths(): void
    {
        $fragmenter = $this->fragmenter();
        $input = ['a/b' => ['~key' => [null, false, 0, []]]];
        $output = $fragmenter->externalize($input, ['/a~1b/~0key/0', '/missing']);

        self::assertInstanceOf(JsonReference::class, $output['a/b']['~key'][0]);
        self::assertSame($input, $fragmenter->resolve($output));
        self::assertSame(['/a~1b/~0key/0'], array_keys(iterator_to_array($fragmenter->references($output))));
    }

    public function testOverlappingPointersAreRejectedBeforeWrites(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('write');
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));

        $this->expectException(InvalidArgumentException::class);
        $fragmenter->externalize(['a' => ['b' => 1]], ['/a', '/a/b']);
    }

    public function testCopyBetweenContextsRequiresExplicitSourceResolution(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage(
            new Filesystem(new PathPrefixedAdapter($adapter, 'owner/A')),
        ));
        $other = new JsonFragmenter(new FlysystemFragmentStorage(
            new Filesystem(new PathPrefixedAdapter($adapter, 'owner/B')),
        ));
        $a = $fragmenter->externalize(['b' => 2, 'a' => 1], ['']);
        $same = $fragmenter->externalize($a, ['']);
        $b = $other->externalize($fragmenter->resolve($a), ['']);

        self::assertSame($a->getRef(), $same->getRef());
        self::assertNotSame($a->getRef(), $b->getRef());
        self::assertEquals($fragmenter->resolve($a), $other->resolve($b));
        $filesystem = new Filesystem($adapter);
        self::assertCount(1, $filesystem->listContents('owner/A')->toArray());
        self::assertCount(1, $filesystem->listContents('owner/B')->toArray());
    }

    public function testResolvedContentIsTerminal(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $stored = (object)['$ref' => 'jsonfragment://nested.json'];
        $storage->expects(self::once())->method('read')->with('outer.json')->willReturn(json_encode($stored));
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));

        self::assertEquals($stored, $fragmenter->resolve(['$ref' => 'jsonfragment://outer.json']));
    }

    public function testEachNewFragmentGetsItsOwnIdentifierUnderFilesystemPrefix(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $filesystem = new Filesystem(new PathPrefixedAdapter($adapter, 'MON_ID'));
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($filesystem));
        $data = ['first' => [1, 2], 'second' => [1, 2]];

        $compressed = $fragmenter->externalize($data, ['/first', '/second']);

        foreach ($compressed as $reference) {
            self::assertMatchesRegularExpression(
                '~^jsonfragment://[a-f0-9]{32}\.json$~',
                $reference->getRef(),
            );
        }
        self::assertNotSame($compressed['first']->getRef(), $compressed['second']->getRef());
        self::assertSame($data, $fragmenter->resolve($compressed));

        $retry = $fragmenter->externalize($compressed, ['/first', '/second']);
        self::assertSame(json_encode($compressed), json_encode($retry));
        self::assertCount(2, (new Filesystem($adapter))->listContents('MON_ID')->toArray());
    }

    public function testReferencesRequireTheOriginalFilesystemContext(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $source = new JsonFragmenter(new FlysystemFragmentStorage(
            new Filesystem(new PathPrefixedAdapter($adapter, 'MON_ID_OTHER')),
        ));
        $target = new JsonFragmenter(new FlysystemFragmentStorage(
            new Filesystem(new PathPrefixedAdapter($adapter, 'MON_ID')),
        ));
        $original = $source->externalize('value', ['']);

        self::assertSame('value', $source->resolve($original));
        $this->expectException(UnableToReadFile::class);
        $target->resolve($original);
    }

    public function testStoreOwnsCustomReferencePrefix(): void
    {
        $store = new FlysystemFragmentStorage(
            new Filesystem(new InMemoryFilesystemAdapter()),
            referencePrefix: 'custom://payloads/',
        );
        $fragmenter = new JsonFragmenter($store);
        $reference = $fragmenter->externalize(['total' => 120], ['']);

        self::assertStringStartsWith('custom://payloads/', $reference->getRef());
        self::assertTrue($store->supports($reference));
        self::assertEquals((object)['total' => 120], $fragmenter->resolve($reference));
        $foreign = ['$ref' => 'jsonfragment://owner/a.json'];
        self::assertSame($foreign, $fragmenter->resolve($foreign));
    }

    public function testFragmenterDelegatesScopeAndReferencePolicyToStore(): void
    {
        $reference = new JsonReference('urn:fragment:123', ['version' => 1]);
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->method('supports')->willReturnCallback(
            static fn(JsonReference $value): bool => 'urn' === $value->getScheme(),
        );
        $store->expects(self::once())->method('store')
            ->with(['total' => 120])->willReturn($reference);
        $store->expects(self::once())->method('resolve')->with($reference)->willReturn(['total' => 120]);
        $fragmenter = new JsonFragmenter($store);

        self::assertSame($reference, $fragmenter->externalize(['total' => 120], ['']));
        $hydrated = $fragmenter->hydrate($reference);
        self::assertInstanceOf(JsonFragment::class, $hydrated);
        self::assertSame(['total' => 120], $fragmenter->resolve($hydrated));
    }

    public function testInspectionAndDehydrationIncludeReferenceSiblings(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('read');
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));
        $input = ['$ref' => 'https://example.org/schema', 'extra' => ['$ref' => 'jsonfragment://a.json']];
        $hydrated = $fragmenter->hydrate($input);

        self::assertInstanceOf(JsonFragment::class, $hydrated['extra']);
        self::assertSame(['', '/extra'], array_keys(iterator_to_array($fragmenter->references($hydrated))));
        self::assertSame(json_encode($input), json_encode($fragmenter->dehydrate($hydrated)));
    }
}
