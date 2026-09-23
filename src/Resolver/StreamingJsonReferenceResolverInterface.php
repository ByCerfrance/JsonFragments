<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Resolver;

use ByCerfrance\JsonFragments\JsonReference;

interface StreamingJsonReferenceResolverInterface extends JsonReferenceResolverInterface
{
    /**
     * Open a blocking, readable stream containing one complete, valid JSON value.
     * Content is consumed from the current position to EOF without rewinding or recursive resolution.
     * Ownership transfers to the caller, who must close the resource, including on early termination.
     *
     * @param JsonReference $reference Supported reference to read.
     * @return resource A PHP stream resource. Opening should not materialize the entire content.
     * @throws \Throwable If the reference is unsupported or the stream cannot be opened.
     */
    public function readStream(JsonReference $reference);
}
