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
     * @var array<int, string>
     */
    public array $host;

    /**
     * @var array<int, string>
     */
    public array $port;

    /**
     * @param  array<int, string>  $ip  Headers naming the client address.
     * @param  array<int, string>  $proto  Headers naming the scheme the client used.
     * @param  array<int, string>  $host  Headers naming the host the client asked for.
     * @param  array<int, string>  $port  Headers naming the port the client connected to.
     */
    public function __construct(array $ip = [], array $proto = ['x-forwarded-proto'], array $host = [], array $port = [])
    {
        $this->ip = $this->normalize($ip);
        $this->proto = $this->normalize($proto);
        $this->host = $this->normalize($host);
        $this->port = $this->normalize($port);
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
