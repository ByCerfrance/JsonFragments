<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests;

use ByCerfrance\JsonFragments\Internal\JsonPointer;
use ByCerfrance\JsonFragments\Internal\JsonPointerPattern;
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
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JsonFragmenter::class)]
#[UsesClass(JsonFragment::class)]
#[UsesClass(JsonReference::class)]
#[UsesClass(JsonPointer::class)]
#[UsesClass(JsonPointerPattern::class)]
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

    public function testExternalizeMatchingNestedAndTerminalWildcards(): void
    {
        $input = json_decode('{"items":[{"detail":[{"test":null},{"test":{"value":42}}]}]}');
        $before = json_encode($input);
        $fragmenter = $this->fragmenter();

        $nested = $fragmenter->externalizeMatching($input, ['/items/*/detail/*/test']);

        self::assertInstanceOf(JsonReference::class, $nested->items[0]->detail[0]->test);
        self::assertInstanceOf(JsonReference::class, $nested->items[0]->detail[1]->test);
        self::assertEquals($input, $fragmenter->resolve($nested));

        $items = $fragmenter->externalizeMatching($input, ['/items/*']);

        self::assertInstanceOf(JsonReference::class, $items->items[0]);
        self::assertEquals($input, $fragmenter->resolve($items));
        self::assertSame($before, json_encode($input));
    }

    public function testMatchingNormalizesSerializableInputOnce(): void
    {
        $input = $this->createMock(\JsonSerializable::class);
        $input->expects(self::once())->method('jsonSerialize')->willReturn(['items' => [42]]);

        $result = $this->fragmenter()->externalizeMatching($input, ['/items/*']);

        self::assertInstanceOf(JsonReference::class, $result['items'][0]);
    }

    public function testExactExternalizationStillTreatsStarAsLiteral(): void
    {
        $result = $this->fragmenter()->externalize(['items' => ['*' => 42, 'other' => 7]], ['/items/*']);

        self::assertInstanceOf(JsonReference::class, $result['items']['*']);
        self::assertSame(7, $result['items']['other']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function conflictingPatterns(): iterable
    {
        yield 'duplicate wildcard' => [['/items/*', '/items/*']];
        yield 'wildcard and exact match' => [['/items/*', '/items/0']];
        yield 'parent before descendant' => [['/items/*', '/items/*/detail/*/test']];
        yield 'descendant before parent' => [['/items/*/detail/*/test', '/items/*']];
        yield 'root and descendant' => [['', '/items/*']];
    }

    /** @param list<string> $patterns */
    #[DataProvider('conflictingPatterns')]
    public function testMatchingRejectsConflictsBeforeStorageAccess(array $patterns): void
    {
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->expects(self::never())->method('store');
        $store->expects(self::never())->method('resolve');
        $fragmenter = new JsonFragmenter($store);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Pointers must not overlap or repeat.');

        $fragmenter->externalizeMatching(['items' => [['detail' => [['test' => 42]]]]], $patterns);
    }

    public function testMatchingValidatesAllBranchesBeforeWriting(): void
    {
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->expects(self::never())->method('store');
        $store->expects(self::never())->method('resolve');
        $fragmenter = new JsonFragmenter($store);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON array index: detail');

        $fragmenter->externalizeMatching(['items' => [['detail' => 42], [7]]], ['/items/*/detail']);
    }

    public function testMatchingReusesSupportedReferenceWithoutResolution(): void
    {
        $reference = new JsonReference('jsonfragment://existing.json');
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->method('supports')->willReturn(true);
        $store->expects(self::never())->method('resolve');
        $store->expects(self::once())->method('store')->with($reference)->willReturn($reference);

        $result = (new JsonFragmenter($store))->externalizeMatching(['items' => [$reference]], ['/items/*']);

        self::assertSame($reference, $result['items'][0]);
    }

    public function testMatchingNoMatchesDoesNotAccessStorage(): void
    {
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->expects(self::never())->method('store');
        $store->expects(self::never())->method('resolve');
        $input = (object)['items' => [null, (object)[], (object)['detail' => []]]];

        $result = (new JsonFragmenter($store))->externalizeMatching($input, [
            '/items/*/detail/*/test', '/missing/*',
        ]);

        self::assertEquals($input, $result);
        self::assertNotSame($input, $result);
    }

    public function testMatchingObjectChildrenPreservesEscapedAndStarKeys(): void
    {
        $input = (object)['items' => (object)['a/b~c' => null, '*' => (object)[]]];
        $fragmenter = $this->fragmenter();

        $result = $fragmenter->externalizeMatching($input, ['/items/*']);

        self::assertInstanceOf(JsonReference::class, $result->items->{'a/b~c'});
        self::assertInstanceOf(JsonReference::class, $result->items->{'*'});
        self::assertEquals($input, $fragmenter->resolve($result));
    }

    public function testMatchingRootCanExternalizeNull(): void
    {
        $fragmenter = $this->fragmenter();

        $result = $fragmenter->externalizeMatching(null, ['']);

        self::assertInstanceOf(JsonReference::class, $result);
        self::assertNull($fragmenter->resolve($result));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPatterns(): iterable
    {
        yield 'non-string' => [42];
        yield 'invalid escape' => ['/missing/~2'];
        yield 'URI fragment' => ['#/items'];
    }

    #[DataProvider('invalidPatterns')]
    public function testMatchingValidatesPatternsBeforeNormalizingOrWriting(mixed $pattern): void
    {
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->expects(self::never())->method('store');
        $input = $this->createMock(\JsonSerializable::class);
        $input->expects(self::never())->method('jsonSerialize');

        $this->expectException(InvalidArgumentException::class);

        (new JsonFragmenter($store))->externalizeMatching($input, ['/items/*', $pattern]);
    }

    /** @return resource */
    private function streamFor(string $json)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $json);
        rewind($stream);

        return $stream;
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
        $storage->expects(self::once())->method('readStream')->with('null.json')->willReturn($this->streamFor('null'));
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
        $storage->expects(self::once())->method('readStream')->with('outer.json')
            ->willReturn($this->streamFor(json_encode($stored)));
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

    public function testValidateReferencesChecksEachSupportedReferenceOnceWithoutReading(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('read');
        $storage->expects(self::never())->method('readStream');
        $checked = [];
        $storage->expects(self::exactly(2))->method('fileExists')->willReturnCallback(
            static function (string $path) use (&$checked): bool {
                $checked[] = $path;

                return true;
            },
        );
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage, storagePrefix: 'results://doc'));
        $input = json_decode(json_encode([
            'a' => ['$ref' => 'jsonfragment://a.json'],
            'list' => [['$ref' => 'jsonfragment://a.json'], ['$ref' => 'jsonfragment://b.json']],
            'web' => ['$ref' => 'https://example.org/schema', 'extra' => ['$ref' => 'jsonfragment://b.json']],
            'local' => ['$ref' => '#/definitions/address'],
            'annotated' => ['$ref' => 'jsonfragment://c.json', 'description' => 'Not our envelope'],
        ]));
        $before = json_encode($input);
        $hydrated = $fragmenter->hydrate($input);

        $fragmenter->validateReferences($hydrated);

        self::assertSame(['results://doc/a.json', 'results://doc/b.json'], $checked);
        self::assertFalse($hydrated->a->isLoaded());
        self::assertSame($before, json_encode($input));
    }

    public function testValidateReferencesChecksRootReference(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('fileExists')->with('root.json')->willReturn(true);
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));

        $fragmenter->validateReferences(new JsonReference('jsonfragment://root.json'));
    }

    public function testValidateReferencesIgnoresDocumentsWithoutSupportedReferences(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('fileExists');
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));

        $fragmenter->validateReferences(['web' => ['$ref' => 'https://example.org/a.json'], 'value' => 1]);
        $this->addToAssertionCount(1);
    }

    public function testValidateReferencesFailsOnMissingFragment(): void
    {
        $fragmenter = $this->fragmenter();
        $document = $fragmenter->externalize(['present' => [1], 'items' => []], ['/present']);
        $document['items'][] = ['$ref' => 'jsonfragment://missing.json'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing JSON fragment jsonfragment://missing.json at "/items/0".');
        $fragmenter->validateReferences($document);
    }

    public function testValidateReferencesPropagatesStorageErrors(): void
    {
        $failure = UnableToCheckFileExistence::forLocation('a.json');
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('fileExists')->willThrowException($failure);
        $fragmenter = new JsonFragmenter(new FlysystemFragmentStorage($storage));

        $this->expectExceptionObject($failure);
        $fragmenter->validateReferences(['$ref' => 'jsonfragment://a.json']);
    }

    public function testValidateReferencesRejectsIncapableResolverBeforeAnyCheck(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('fileExists');
        $fragment = new JsonFragment(
            new JsonReference('jsonfragment://a.json'),
            new FlysystemFragmentStorage($storage),
        );
        $store = $this->createMock(JsonFragmentStoreInterface::class);
        $store->method('supports')->willReturnCallback(
            static fn(JsonReference $value): bool => 'urn' === $value->getScheme(),
        );
        $store->expects(self::never())->method('resolve');
        $fragmenter = new JsonFragmenter($store);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The resolver for urn:fragment:1 at "/custom" cannot check fragment existence.');
        $fragmenter->validateReferences([
            'fragment' => $fragment,
            'custom' => ['$ref' => 'urn:fragment:1'],
            'web' => ['$ref' => 'https://example.org/a.json'],
        ]);
    }

    public function testValidateReferencesUsesEachFragmentStorageContext(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $source = new JsonFragmenter(new FlysystemFragmentStorage(
            new Filesystem(new PathPrefixedAdapter($adapter, 'A')),
        ));
        $target = new JsonFragmenter(new FlysystemFragmentStorage(
            new Filesystem(new PathPrefixedAdapter($adapter, 'B')),
        ));
        $reference = $source->externalize(['value' => 1], ['']);
        $document = ['fragment' => $source->hydrate($reference)];

        $target->validateReferences($document);

        $document['raw'] = $reference;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at "/raw"');
        $target->validateReferences($document);
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
