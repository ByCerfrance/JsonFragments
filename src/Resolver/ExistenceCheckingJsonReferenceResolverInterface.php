<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Resolver;

use ByCerfrance\JsonFragments\JsonReference;

interface ExistenceCheckingJsonReferenceResolverInterface extends JsonReferenceResolverInterface
{
    /**
     * Check whether a supported reference exists, without opening, reading or decoding its content.
     * Never report existence that has not actually been determined.
     *
     * @param JsonReference $reference Supported reference to check.
     * @return bool True if the target exists, false if it is confirmed missing.
     * @throws \Throwable If the reference is unsupported or existence cannot be determined.
     */
    public function exists(JsonReference $reference): bool;
}
