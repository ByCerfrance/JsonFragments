<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\JsonReference;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonReference::class)]
#[UsesClass(JsonValue::class)]
final class JsonReferenceTest extends TestCase
{
    #[DataProvider('schemes')]
    public function testSchemeIsDerivedWithoutAlteringReference(string $ref, ?string $scheme): void
    {
        $reference = new JsonReference($ref);

        self::assertSame($ref, $reference->getRef());
        self::assertSame($scheme, $reference->getScheme());
        self::assertSame($ref, $reference->jsonSerialize()->{'$ref'});
    }

    public static function schemes(): iterable
    {
        yield 'storage' => ['jsonfragment://owner/a.json', 'jsonfragment'];
        yield 'web uppercase' => ['HTTPS://example.org/schema', 'https'];
        yield 'URN' => ['urn:fragment:123', 'urn'];
        yield 'compound scheme' => ['git+ssh://example.org/repo', 'git+ssh'];
        yield 'local pointer' => ['#/definitions/address', null];
        yield 'relative' => ['schemas/invoice.json', null];
        yield 'empty' => ['', null];
        yield 'invalid scheme' => ['1http:example', null];
    }

    public function testSiblingPropertiesRoundTripIncludingNumericNames(): void
    {
        $reference = new JsonReference('https://example.org/schema', [
            'description' => 'Invoice',
            'nullable' => null,
            '1' => 'numeric property',
            'nested' => (object)['empty' => (object)[]],
        ]);

        $decoded = json_decode(json_encode($reference, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://example.org/schema', $decoded->{'$ref'});
        self::assertSame('Invoice', $decoded->description);
        self::assertSame('numeric property', $decoded->{'1'});
        self::assertTrue(property_exists($decoded, 'nullable'));
        self::assertEquals((object)[], $decoded->nested->empty);
    }

    public function testPropertiesAreDetachedOnInputAndOutput(): void
    {
        $nested = (object)['value' => 'original'];
        $reference = new JsonReference('ref', ['nested' => $nested]);
        $nested->value = 'input changed';
        $properties = $reference->getProperties();
        $properties['nested']->value = 'output changed';
        $serialized = $reference->jsonSerialize();
        $serialized->nested->value = 'serialization changed';

        self::assertSame('original', $reference->getProperties()['nested']->value);
    }

    public function testPropertiesCannotOverrideReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JsonReference('original', ['$ref' => 'replacement']);
    }

    public function testInvalidUtf8ReferenceFailsExplicitly(): void
    {
        $this->expectException(JsonException::class);
        new JsonReference("\xB1\x31");
    }
}
