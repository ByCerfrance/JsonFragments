<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Internal;

use ByCerfrance\JsonFragments\Internal\JsonValue;
use ByCerfrance\JsonFragments\JsonFragment;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Resolver\JsonReferenceResolverInterface;
use ByCerfrance\JsonFragments\Tests\Fixture\IntBackedEnum;
use ByCerfrance\JsonFragments\Tests\Fixture\PureEnum;
use ByCerfrance\JsonFragments\Tests\Fixture\SerializableBackedEnum;
use ByCerfrance\JsonFragments\Tests\Fixture\StringBackedEnum;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonValue::class)]
#[UsesClass(JsonFragment::class)]
#[UsesClass(JsonReference::class)]
final class JsonValueTest extends TestCase
{
    public function testCopyNormalizesBackedEnumsToTheirScalarValues(): void
    {
        self::assertSame('ready', JsonValue::copy(StringBackedEnum::Ready));
        self::assertSame(0, JsonValue::copy(IntBackedEnum::Zero));
    }

    public function testNestedBackedEnumsPreserveStructureWithoutMutatingInput(): void
    {
        $object = (object)['status' => StringBackedEnum::Ready, 'code' => IntBackedEnum::Zero];
        $input = ['items' => [$object]];

        $copy = JsonValue::copy($input);

        self::assertNotSame($object, $copy['items'][0]);
        self::assertSame('ready', $copy['items'][0]->status);
        self::assertSame(0, $copy['items'][0]->code);
        self::assertSame(StringBackedEnum::Ready, $object->status);
        self::assertSame(IntBackedEnum::Zero, $object->code);
        self::assertSame('{"items":[{"code":0,"status":"ready"}]}', JsonValue::encode($input));
    }

    public function testJsonSerializableTakesPriorityOverBackingValue(): void
    {
        self::assertSame(['status' => 'ready'], JsonValue::copy(SerializableBackedEnum::Ready));
        self::assertSame('{"status":"ready"}', JsonValue::encode(SerializableBackedEnum::Ready));
    }

    public function testCopyNormalizesBackedEnumReturnedByJsonSerializable(): void
    {
        $serializable = new class implements JsonSerializable {
            #[Override]
            public function jsonSerialize(): mixed
            {
                return IntBackedEnum::Zero;
            }
        };

        self::assertSame(0, JsonValue::copy($serializable));
        self::assertSame('0', JsonValue::encode($serializable));
    }

    public function testBackedEnumWithInvalidUtf8IsRejectedDuringCopy(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_UTF8);

        JsonValue::copy(StringBackedEnum::InvalidUtf8);
    }

    public function testPureEnumWithoutJsonSerializableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonValue::copy(PureEnum::Ready);
    }

    public function testCopyNormalizesNestedSerializableValuesWithoutMutatingInput(): void
    {
        $object = (object)['amount' => 120];
        $serializable = new class ($object) implements JsonSerializable {
            public function __construct(private object $value)
            {
            }

            #[Override]
            public function jsonSerialize(): mixed
            {
                return $this->value;
            }
        };
        $copy = JsonValue::copy(['value' => $serializable]);
        $copy['value']->amount = 999;

        self::assertSame(120, $object->amount);
    }

    public function testEncodingSortsObjectsWithoutReorderingListsOrLosingTypes(): void
    {
        $input = ['z' => [3, 1, 2], 'a' => (object)[], 'b' => [], 'c' => 1.0];
        self::assertSame('{"a":{},"b":[],"c":1.0,"z":[3,1,2]}', JsonValue::encode($input));
        self::assertSame('{"0":"zero"}', JsonValue::encode((object)['0' => 'zero']));
    }

    public function testCopyNeverLoadsFragmentsAndEncodingRequiresExplicitResolution(): void
    {
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->expects(self::never())->method('resolve');
        $fragment = new JsonFragment(new JsonReference('urn:fragment:1'), $resolver);
        self::assertSame($fragment, JsonValue::copy(['fragment' => $fragment])['fragment']);

        $this->expectException(InvalidArgumentException::class);
        JsonValue::encode($fragment);
    }

    public function testObjectCyclesAreRejected(): void
    {
        $object = (object)[];
        $object->self = $object;

        $this->expectException(InvalidArgumentException::class);
        JsonValue::copy($object);
    }

    public function testArrayCyclesAreRejected(): void
    {
        $array = [];
        $array['self'] = &$array;

        $this->expectException(InvalidArgumentException::class);
        JsonValue::copy($array);
    }

    public function testResourcesAreRejected(): void
    {
        $resource = fopen('php://memory', 'r+');
        try {
            $this->expectException(InvalidArgumentException::class);
            JsonValue::copy($resource);
        } finally {
            fclose($resource);
        }
    }
}
