<?php

declare(strict_types=1);

namespace Utopia\Queue;

/**
 * Turns a message envelope into the bytes a broker carries, and back.
 *
 * Both directions throw on failure: an unencodable value, or bytes that are
 * not a payload this codec wrote. A broker treats a decode failure as a poison
 * message and parks it -- unlike a cache, it cannot shrug one off as a miss,
 * because the bytes are the only copy of somebody's work.
 */
interface Codec
{
    /**
     * @throws \Throwable
     */
    public function encode(mixed $value): string;

    /**
     * @throws \Throwable
     */
    public function decode(string $value): mixed;

    /**
     * The media type of what {@see self::encode()} writes.
     *
     * Brokers that carry metadata beside the payload publish it, so a consumer
     * reading the stream can tell which format a message is in without
     * inspecting its bytes.
     */
    public function contentType(): string;
}
