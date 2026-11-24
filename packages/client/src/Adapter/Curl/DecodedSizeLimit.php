<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\Curl;

use Closure;
use CurlHandle;
use InvalidArgumentException;

/**
 * Applies CURLOPT_MAXFILESIZE / CURLOPT_MAXFILESIZE_LARGE to the decoded response body. Older libcurl checks them
 * only against the bytes on the wire, so a small compressed body could expand far past them in memory.
 *
 * @internal Used by the cURL adapter, one instance per transfer.
 */
final class DecodedSizeLimit
{
    private int $received = 0;

    private function __construct(public readonly int $bytes)
    {
    }

    /**
     * The cap the options configure, or null for none. cURL reads both options as integers the way PHP casts a
     * scalar, so a numeric string such as '1048576' sets the same cap here as on the wire. When both are set, the
     * smaller applies.
     *
     * @param array<int, mixed> $options
     */
    public static function fromOptions(array $options): ?self
    {
        $limits = [];
        foreach ([\CURLOPT_MAXFILESIZE_LARGE, \CURLOPT_MAXFILESIZE] as $option) {
            $value = $options[$option] ?? null;
            if (\is_scalar($value) && (int) $value > 0) {
                $limits[] = (int) $value;
            }
        }

        return $limits === [] ? null : new self(\min($limits));
    }

    /**
     * Wrap a CURLOPT_WRITEFUNCTION so the transfer aborts, with `$exceeded` set, once the bytes handed to it pass
     * the cap. Below the cap each chunk goes to `$write` and its count is returned to cURL.
     *
     * @return Closure(CurlHandle, string): int
     */
    public function wrap(mixed $write, bool &$exceeded): Closure
    {
        if (!\is_callable($write)) {
            throw new InvalidArgumentException('CURLOPT_WRITEFUNCTION must be callable.');
        }

        return function (CurlHandle $handle, string $chunk) use ($write, &$exceeded): int {
            $this->received += \strlen($chunk);

            if ($this->received > $this->bytes) {
                $exceeded = true;

                // Any count other than the chunk's length aborts the transfer.
                return 0;
            }

            $written = $write($handle, $chunk);

            return \is_numeric($written) ? (int) $written : 0;
        };
    }
}
