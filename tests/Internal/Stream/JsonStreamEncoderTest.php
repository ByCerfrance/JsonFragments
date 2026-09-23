<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Internal\Stream;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\Internal\Stream\JsonStreamEncoder;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Resolver\JsonReferenceResolverInterface;
use ByCerfrance\JsonFragments\Resolver\StreamingJsonReferenceResolverInterface;
use JsonSerializable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonStreamEncoder::class)]
#[UsesClass(JsonReference::class)]
#[UsesClass(JsonValue::class)]
final class JsonStreamEncoderTest extends TestCase
{
    public function testResourceOwnershipTransfersToConsumerWithoutReading(): void
    {
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, '[1,2]');
        rewind($resource);
        $resolver = $this->createStub(StreamingJsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->method('readStream')->willReturn($resource);
        $segments = (new JsonStreamEncoder($resolver))->encode(new JsonReference('urn:stream'));

        try {
            self::assertSame($resource, $segments->current());
            self::assertSame(0, ftell($resource));
            $segments->next();
            self::assertFalse($segments->valid());
            self::assertTrue(is_resource($resource));
        } finally {
            fclose($resource);
        }
    }

    public function testNestedSerializableObjectsAreEncodedOnDemand(): void
    {
        $value = $this->createMock(JsonSerializable::class);
        $value->expects(self::once())->method('jsonSerialize')->willReturn(['measure' => 1.0]);
        $resolver = $this->createStub(JsonReferenceResolverInterface::class);
        $segments = (new JsonStreamEncoder($resolver))->encode(['value' => $value]);

        self::assertSame('{"value":{"measure":1.0}}', implode('', iterator_to_array($segments, false)));
    }

    public function testFallbackPreservesReferencesWithPropertiesInsideResolvedValue(): void
    {
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->expects(self::once())->method('resolve')->willReturn([
            new JsonReference('urn:nested', ['description' => 'Keep as data']),
        ]);
        $segments = (new JsonStreamEncoder($resolver))->encode(new JsonReference('urn:outer'));

        self::assertSame(
            '[{"$ref":"urn:nested","description":"Keep as data"}]',
            implode('', iterator_to_array($segments, false)),
        );
    }
}
