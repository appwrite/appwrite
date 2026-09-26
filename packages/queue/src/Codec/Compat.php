<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use RuntimeException;
use Utopia\Queue\Codec;

/**
 * Reads either encoding, writes one of them.
 *
 * Changing a queue's codec is not like changing a cache's. A cache can treat
 * bytes it cannot read as a miss and fetch again; a queue holds the only copy
 * of work somebody asked for, and messages written before the deploy are still
 * on the list, still in flight, and still on the dead letters nobody has
 * redriven yet. So the cutover runs in two steps -- deploy reading both and
 * writing what it wrote yesterday, then flip the writer -- and the reader stays
 * here afterwards, because a dead-letter list has no deadline.
 */
final class Compat implements Codec
{
    /** igbinary's header: a NUL byte and the format version, currently 2. */
    private const string IGBINARY_MAGIC = "\x00\x00\x00\x02";

    private readonly Codec $json;
    private ?Codec $igbinary;

    public function __construct(private readonly Codec $writer = new Json())
    {
        $this->json = $writer instanceof Json ? $writer : new Json();
        // Built on demand: step one of the cutover writes JSON on hosts that do
        // not have the extension yet, and constructing the reader eagerly would
        // refuse to boot there for a format nothing has written.
        $this->igbinary = $writer instanceof Igbinary ? $writer : null;
    }

    public function encode(mixed $value): string
    {
        return $this->writer->encode($value);
    }

    public function decode(string $value): mixed
    {
        return $this->codecFor($value)->decode($value);
    }

    /**
     * What the writer produces, not what the reader accepts: the header
     * describes the message it is attached to, and during the cutover that is
     * whichever format this deploy is still writing.
     */
    public function contentType(): string
    {
        return $this->writer->contentType();
    }

    /**
     * Pick a reader from the leading bytes.
     *
     * Sniffing rather than a version header, because the bytes on the queue
     * today carry no header and never will -- and the two formats cannot be
     * confused: an igbinary payload opens with a NUL, which no JSON document
     * may contain at all, let alone begin with.
     */
    private function codecFor(string $value): Codec
    {
        if (str_starts_with($value, self::IGBINARY_MAGIC)) {
            return $this->igbinary ??= new Igbinary();
        }

        if ($value !== '' && $value[0] === "\x00") {
            throw new RuntimeException('Value carries an unsupported igbinary format version.');
        }

        return $this->json;
    }
}
