<?php

namespace Appwrite\URL;

class URL
{
    /**
     * Parse URL
     *
     * Take a URL string and split it to array parts
     *
     * @param string $url
     *
     * @return array
     */
    public static function parse(string $url): array
    {
        $default = [
            'scheme' => '',
            'pass' => '',
            'user' => '',
            'host' => '',
            'port' => null,
            'path' => '',
            'query' => '',
            'fragment' => '',
        ];

        $parsed = \parse_url($url);
        if (is_array($parsed)) {
            return \array_merge($default, $parsed);
        }

        // see if $url is just a scheme
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches)) {
            $scheme = $matches[1];
            return \array_merge($default, [
                'scheme' => $scheme
            ]);
        }

        throw new \InvalidArgumentException('Invalid URL: ' . $url);
    }

    /**
     * Un-Parse URL
     *
     * Take URL parts and combine them to a valid string
     *
     * @param array $url
     * @param array $ommit
     *
     * @return string
     */
    public static function unparse(array $url, array $ommit = []): string
    {
        if (isset($url['path']) && \mb_substr($url['path'], 0, 1) !== '/') {
            $url['path'] = '/' . $url['path'];
        }

        $parts = [];

        $parts['scheme'] = isset($url['scheme']) ? $url['scheme'] . '://' : '';

        $parts['host'] = isset($url['host']) ? $url['host'] : '';

        $parts['port'] = isset($url['port']) ? ':' . $url['port'] : '';

        $hasUser = isset($url['user']) && $url['user'] !== '';
        $hasPass = isset($url['pass']) && $url['pass'] !== '';

        $parts['user'] = $hasUser ? $url['user'] : '';

        $parts['pass'] = $hasPass ? ':' . $url['pass'] : '';

        $parts['pass'] = ($hasUser || $hasPass) ? $parts['pass'] . '@' : '';

        $parts['path'] = isset($url['path']) ? $url['path'] : '';

        $parts['query'] = isset($url['query']) && !empty($url['query']) ? '?' . $url['query'] : '';

        $parts['fragment'] = isset($url['fragment']) ? '#' . $url['fragment'] : '';

        if ($ommit) {
            foreach ($ommit as $key) {
                if (isset($parts[ $key ])) {
                    $parts[ $key ] = '';
                }
            }
        }

        return $parts['scheme'] . $parts['user'] . $parts['pass'] . $parts['host'] . $parts['port'] . $parts['path'] . $parts['query'] . $parts['fragment'];
    }

    /**
     * Parse Query String
     *
     * Convert query string to array
     *
     * @param string $query
     *
     * @return array
     */
    public static function parseQuery(string $query): array
    {
        \parse_str($query, $result);

        return $result;
    }

    /**
     * Un-Parse Query String
     *
     * Convert query string array to string
     *
     * @param array $query
     *
     * @return string
     */
    public static function unparseQuery(array $query): string
    {
        return \http_build_query($query);
    }

    /**
     * Resolve a Location-header reference against an absolute base URL,
     * following RFC 3986 §5.3 (Reference Resolution). Handles:
     *  - absolute references (scheme present)
     *  - protocol-relative (//host/path)
     *  - absolute-path (/foo)
     *  - relative-path (foo, ../foo)
     *  - query-only (?x=1)            -- keeps base path
     *  - fragment-only (#frag)        -- keeps base path AND base query
     *  - dot-segment normalisation    -- /a/b/../c → /a/c
     */
    public static function resolveLocation(string $base, string $reference): string
    {
        $reference = \trim($reference);
        if ($reference === '') {
            return $base;
        }

        $ref = \parse_url($reference);
        $bas = \parse_url($base);
        if (!\is_array($ref) || !\is_array($bas)) {
            return $reference;
        }

        $target = [];

        if (isset($ref['scheme'])) {
            $target['scheme'] = $ref['scheme'];
            self::copyAuthority($ref, $target);
            $target['path'] = self::removeDotSegments($ref['path'] ?? '');
            if (isset($ref['query'])) {
                $target['query'] = $ref['query'];
            }
        } else {
            $target['scheme'] = $bas['scheme'] ?? '';

            if (isset($ref['host'])) {
                self::copyAuthority($ref, $target);
                $target['path'] = self::removeDotSegments($ref['path'] ?? '');
                if (isset($ref['query'])) {
                    $target['query'] = $ref['query'];
                }
            } else {
                self::copyAuthority($bas, $target);
                $refPath = $ref['path'] ?? '';

                if ($refPath === '') {
                    $target['path'] = $bas['path'] ?? '';
                    if (isset($ref['query'])) {
                        $target['query'] = $ref['query'];
                    } elseif (isset($bas['query'])) {
                        $target['query'] = $bas['query'];
                    }
                } else {
                    if (\str_starts_with($refPath, '/')) {
                        $target['path'] = self::removeDotSegments($refPath);
                    } else {
                        $target['path'] = self::removeDotSegments(self::mergePaths($bas, $refPath));
                    }
                    if (isset($ref['query'])) {
                        $target['query'] = $ref['query'];
                    }
                }
            }
        }

        if (isset($ref['fragment'])) {
            $target['fragment'] = $ref['fragment'];
        }

        return self::unparse($target);
    }

    /**
     * @param array $source
     * @param array $target
     */
    private static function copyAuthority(array $source, array &$target): void
    {
        foreach (['user', 'pass', 'host', 'port'] as $key) {
            if (isset($source[$key])) {
                $target[$key] = $source[$key];
            }
        }
    }

    /**
     * RFC 3986 §5.2.3 — merge base path with reference path.
     */
    private static function mergePaths(array $base, string $referencePath): string
    {
        if (isset($base['host']) && empty($base['path'])) {
            return '/' . $referencePath;
        }

        $basePath = $base['path'] ?? '';
        $slash = \strrpos($basePath, '/');
        if ($slash === false) {
            return $referencePath;
        }

        return \substr($basePath, 0, $slash + 1) . $referencePath;
    }

    /**
     * RFC 3986 §5.2.4 — remove "." and ".." segments from a path.
     */
    private static function removeDotSegments(string $path): string
    {
        $output = '';
        $input = $path;

        while ($input !== '') {
            if (\str_starts_with($input, '../')) {
                $input = \substr($input, 3);
            } elseif (\str_starts_with($input, './')) {
                $input = \substr($input, 2);
            } elseif (\str_starts_with($input, '/./')) {
                $input = '/' . \substr($input, 3);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (\str_starts_with($input, '/../')) {
                $input = '/' . \substr($input, 4);
                $slash = \strrpos($output, '/');
                $output = $slash === false ? '' : \substr($output, 0, $slash);
            } elseif ($input === '/..') {
                $input = '/';
                $slash = \strrpos($output, '/');
                $output = $slash === false ? '' : \substr($output, 0, $slash);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $start = ($input[0] === '/') ? 1 : 0;
                $next = \strpos($input, '/', $start);
                if ($next === false) {
                    $output .= $input;
                    $input = '';
                } else {
                    $output .= \substr($input, 0, $next);
                    $input = \substr($input, $next);
                }
            }
        }

        return $output;
    }
}
