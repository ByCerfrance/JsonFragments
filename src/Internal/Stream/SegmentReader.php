<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Internal\Stream;

use Generator;
use RuntimeException;
use Throwable;

/** @internal Owns and closes the current resource segment and releases the producer on close. */
final class SegmentReader
{
    private mixed $segment = null;
    private int $offset = 0;
    private int $position = 0;
    private bool $advance = false;

    /** @param Generator<int, string|resource> $segments */
    public function __construct(private ?Generator $segments)
    {
    }

    public function read(int $count): string
    {
        $output = '';
        try {
            while (strlen($output) < $count && null !== $this->segments) {
                if (null === $this->segment) {
                    if ($this->advance) {
                        $this->segments->next();
                    }
                    if (!$this->segments->valid()) {
                        $this->close();
                        break;
                    }
                    $this->segment = $this->segments->current();
                    $this->advance = true;
                    $this->offset = 0;
                    if (!is_string($this->segment)
                        && (!is_resource($this->segment) || 'stream' !== get_resource_type($this->segment))) {
                        throw new RuntimeException('Expected a string or a readable stream segment.');
                    }
                    if (is_resource($this->segment)
                        && !preg_match('/[r+]/', stream_get_meta_data($this->segment)['mode'])) {
                        throw new RuntimeException('The JSON stream segment is not readable.');
                    }
                }

                $remaining = $count - strlen($output);
                if (is_string($this->segment)) {
                    $chunk = substr($this->segment, $this->offset, $remaining);
                    $this->offset += strlen($chunk);
                    if ($this->offset >= strlen($this->segment)) {
                        $this->segment = null;
                    }
                } else {
                    $chunk = fread($this->segment, $remaining);
                    if (false === $chunk || ('' === $chunk && !feof($this->segment))) {
                        throw new RuntimeException('Unable to read JSON stream segment; blocking streams are required.');
                    }
                    if (feof($this->segment)) {
                        fclose($this->segment);
                        $this->segment = null;
                    }
                }
                $output .= $chunk;
            }
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }

        $this->position += strlen($output);

        return $output;
    }

    public function eof(): bool
    {
        return null === $this->segments;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function close(): void
    {
        if (is_resource($this->segment)) {
            fclose($this->segment);
        }
        $this->segment = null;
        $this->segments = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
