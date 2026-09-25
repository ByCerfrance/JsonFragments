<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Fixture;

enum StringBackedEnum: string
{
    case Ready = 'ready';
    case InvalidUtf8 = "\xB1\x31";
}
