<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Fixture;

use JsonSerializable;
use Override;

enum SerializableBackedEnum: string implements JsonSerializable
{
    case Ready = 'backing-value';

    #[Override]
    public function jsonSerialize(): mixed
    {
        return ['status' => StringBackedEnum::Ready];
    }
}
