<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Fixture;

use ByCerfrance\JsonFragments\Resolver\StreamingJsonReferenceResolverInterface;
use ByCerfrance\JsonFragments\Storage\JsonFragmentStoreInterface;

interface StreamingStoreInterface extends JsonFragmentStoreInterface, StreamingJsonReferenceResolverInterface
{
}
