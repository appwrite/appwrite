<?php

namespace Appwrite\Network;

use Appwrite\Network\Validator\PublicHostname;
use Appwrite\URL\URL;
use Utopia\Domains\Domain;
use Utopia\Fetch\Adapter;
use Utopia\Fetch\Client;
use Utopia\Fetch\Response;

/**
 * HTTP fetcher with built-in SSRF protection.
 *
 * Use this whenever the URL comes from user input. The fetcher disables
 * curl's automatic redirect handling and walks the redirect chain itself,
 * re-validating scheme + PSL membership + publicly-routable resolution
 * (via [[PublicHostname]]) on every hop. This is what prevents an
 * attacker-controlled page from bouncing to an internal address such as
 * 169.254.169.254 (AWS IMDS) or a private cluster service.
 */
class Fetcher
{
    public const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly string $userAgent = '',
        private readonly int $maxRedirects = 5,
        private readonly ?Adapter $adapter = null,
    ) {
    }

    /**
     * Fetch a URL, validating every hop in the redirect chain.
     *
     * @throws UnsafeUrlException if the initial URL or any redirect target
     *         is malformed, uses a non-http(s) scheme, or resolves to a
     *         private/loopback/link-local/reserved address.
     * @throws \RuntimeException  if the redirect limit is exceeded.
     */
    public function fetch(string $url): Response
    {
        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            self::assertSafe($url);

            $client = $this->adapter !== null ? new Client($this->adapter) : new Client();
            $response = $client
                ->setAllowRedirects(false)
                ->setUserAgent($this->userAgent)
                ->fetch($url);

            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400) {
                return $response;
            }

            // RFC 7230 §3.2 — header names are case-insensitive. Utopia's
            // current curl adapter already lowercases, but normalising here
            // keeps Fetcher correct for any future Adapter implementation
            // (and for test mocks that pass headers verbatim).
            $headers = \array_change_key_case($response->getHeaders(), CASE_LOWER);
            $location = $headers['location'] ?? '';
            if ($location === '') {
                return $response;
            }

            $url = URL::resolveLocation($url, $location);
        }

        throw new \RuntimeException('Too many redirects.');
    }

    /**
     * Validate that a URL is safe to fetch from a server-side context.
     *
     * Rejects non-http(s) schemes, hosts that aren't on the public suffix
     * list (unless they're an IP literal), and any hostname that resolves
     * to a private, loopback, link-local, multicast, or reserved address.
     *
     * @throws UnsafeUrlException
     */
    public static function assertSafe(string $url): void
    {
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            throw new UnsafeUrlException('Malformed URL.');
        }

        $scheme = \strtolower($parts['scheme'] ?? '');
        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new UnsafeUrlException("Scheme '{$scheme}' is not allowed.");
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new UnsafeUrlException('URL has no host.');
        }

        $isIpLiteral = \filter_var(\trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
        if (!$isIpLiteral) {
            try {
                $domain = new Domain($host);
            } catch (\Throwable) {
                throw new UnsafeUrlException("Hostname '{$host}' is invalid.");
            }

            if (!$domain->isKnown()) {
                throw new UnsafeUrlException("Hostname '{$host}' is not a known public domain.");
            }
        }

        $validator = new PublicHostname();
        if (!$validator->isValid($host)) {
            throw new UnsafeUrlException($validator->getDescription());
        }
    }
}
