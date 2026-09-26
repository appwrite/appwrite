<?php

declare(strict_types=1);

namespace Utopia\Http;

/**
 * Which forwarded headers this server believes.
 *
 * A header is only worth reading where the hop in front of this server
 * overwrites it; anywhere else the client chooses its own answer. Each list is
 * read in order and the first usable value wins.
 */
final readonly class TrustedHeaders
{
    /**
     * @var array<int, string>
     */
    public array $ip;

    /**
     * @var array<int, string>
     */
    public array $proto;

    /**
     * @param  array<int, string>  $ip  Headers naming the client address.
     * @param  array<int, string>  $proto  Headers naming the scheme the client used.
     */
    public function __construct(array $ip = [], array $proto = ['x-forwarded-proto'])
    {
        $this->ip = $this->normalize($ip);
        $this->proto = $this->normalize($proto);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    private function normalize(array $headers): array
    {
        $lowered = array_map(strtolower(...), $headers);
        $trimmed = array_map(trim(...), $lowered);

        return array_values(array_filter($trimmed));
    }
}
