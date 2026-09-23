<?php

declare(strict_types=1);

namespace Utopia\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Utopia\Psr7\Header;
use Utopia\Psr7\Uri;

/**
 * Shared redirect policy used by adapters that follow Location themselves
 * (Swoole) and by tests that pin RFC 3986 resolution and credential stripping.
 * cURL applies the same rules inside libcurl.
 */
final class Redirect
{
    public const int MAX_HOPS = 50;

    /**
     * @var list<string>
     */
    private const array SENSITIVE_HEADERS = [
        Header::AUTHORIZATION,
        Header::COOKIE,
        'Cookie2',
        'Proxy-Authorization',
    ];

    private function __construct() {}

    public static function isRedirect(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 300 && $status < 400 && $response->getHeaderLine(Header::LOCATION) !== '';
    }

    public static function resolve(UriInterface $base, string $location): UriInterface
    {
        $target = Uri::parse($location);

        if ($target->getScheme() !== '') {
            return $target->withPath(self::removeDotSegments($target->getPath()));
        }

        if ($target->getHost() !== '') {
            return $target
                ->withScheme($base->getScheme())
                ->withPath(self::removeDotSegments($target->getPath()));
        }

        $path = $target->getPath();

        if ($path === '') {
            $query = self::locationHasQuery($location) ? $target->getQuery() : $base->getQuery();

            return $base
                ->withQuery($query)
                ->withFragment($target->getFragment());
        }

        if (str_starts_with($path, '/')) {
            return $base
                ->withPath(self::removeDotSegments($path))
                ->withQuery($target->getQuery())
                ->withFragment($target->getFragment());
        }

        return $base
            ->withPath(self::removeDotSegments(self::merge($base, $path)))
            ->withQuery($target->getQuery())
            ->withFragment($target->getFragment());
    }

    /**
     * RFC 3986 section 5.2.4 remove_dot_segments algorithm.
     */
    public static function removeDotSegments(string $path): string
    {
        $output = '';

        while ($path !== '') {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './')) {
                $path = substr($path, 2);
            } elseif (str_starts_with($path, '/./')) {
                $path = substr($path, 2);
            } elseif ($path === '/.') {
                $path = '/';
            } elseif (str_starts_with($path, '/../')) {
                $path = substr($path, 3);
                $output = self::withoutLastPathSegment($output);
            } elseif ($path === '/..') {
                $path = '/';
                $output = self::withoutLastPathSegment($output);
            } elseif ($path === '.' || $path === '..') {
                $path = '';
            } else {
                $nextSlash = strpos($path, '/', str_starts_with($path, '/') ? 1 : 0);

                if ($nextSlash === false) {
                    $output .= $path;
                    $path = '';
                } else {
                    $output .= substr($path, 0, $nextSlash);
                    $path = substr($path, $nextSlash);
                }
            }
        }

        return $output;
    }

    public static function isSameOrigin(UriInterface $from, UriInterface $to): bool
    {
        return self::origin($from) === self::origin($to);
    }

    /**
     * Strip credentials when the origin changes. Origin includes the scheme, so
     * an HTTPS to HTTP hop on the same host is stripped too.
     */
    public static function shouldStripSensitiveHeaders(UriInterface $from, UriInterface $to): bool
    {
        return !self::isSameOrigin($from, $to);
    }

    public static function withoutSensitiveHeaders(RequestInterface $request): RequestInterface
    {
        foreach (self::SENSITIVE_HEADERS as $header) {
            $request = $request->withoutHeader($header);
        }

        return $request;
    }

    private static function locationHasQuery(string $location): bool
    {
        return str_contains(explode('#', $location, 2)[0], '?');
    }

    private static function withoutLastPathSegment(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    /**
     * RFC 3986 5.2.3: merge a relative path against the base URI's path.
     */
    private static function merge(UriInterface $base, string $relative): string
    {
        $basePath = $base->getPath();

        if ($base->getAuthority() !== '' && $basePath === '') {
            return '/' . $relative;
        }

        $slash = strrpos($basePath, '/');

        if ($slash === false) {
            return $relative;
        }

        return substr($basePath, 0, $slash + 1) . $relative;
    }

    private static function origin(UriInterface $uri): string
    {
        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort();

        if ($port === null) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        return $scheme . '://' . strtolower($uri->getHost()) . ':' . $port;
    }
}
