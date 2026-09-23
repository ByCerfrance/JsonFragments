<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Internal\Stream;

use Generator;
use RuntimeException;

/** @internal PHP stream wrapper; each resource owns a reader through its private context. */
final class JsonStreamWrapper
{
    private const SCHEME = 'bycerfrance-json-fragments';
    private static bool $registered = false;

    /** @var resource|null Populated by PHP when opening the stream. */
    public $context;
    private ?SegmentReader $reader = null;

    /**
     * @param Generator<int, string|resource> $segments
     * @return resource
     */
    public static function open(Generator $segments)
    {
        if (!self::$registered) {
            if (in_array(self::SCHEME, stream_get_wrappers(), true)
                || !stream_wrapper_register(self::SCHEME, self::class)) {
                throw new RuntimeException('Unable to register the JSON stream wrapper.');
            }
            self::$registered = true;
        }

        $context = stream_context_create([self::SCHEME => ['reader' => new SegmentReader($segments)]]);
        $stream = fopen(self::SCHEME . '://document', 'rb', false, $context);
        if (false === $stream) {
            throw new RuntimeException('Unable to open the JSON stream.');
        }

        // PHP initially marks user wrappers as seekable. A missing seek callback makes this
        // probe fail without reading, and sets PHP_STREAM_FLAG_NO_SEEK for PSR-7 consumers.
        @fseek($stream, 0, SEEK_END);

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!in_array($mode, ['r', 'rb'], true) || !is_resource($this->context)) {
            return false;
        }
        $reader = stream_context_get_options($this->context)[self::SCHEME]['reader'] ?? null;
        if (!$reader instanceof SegmentReader) {
            return false;
        }
        $this->reader = $reader;

        return true;
    }

    public function stream_read(int $count): string
    {
        return $this->reader?->read($count) ?? '';
    }

    public function stream_eof(): bool
    {
        return $this->reader?->eof() ?? true;
    }

    public function stream_tell(): int
    {
        return $this->reader?->tell() ?? 0;
    }

    public function stream_stat(): false
    {
        return false;
    }

    public function stream_set_option(int $option, ?int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->reader?->close();
        $this->reader = null;
    }
}
