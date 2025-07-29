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

        $parts['user'] = isset($url['user']) ? $url['user'] : '';

        $parts['pass'] = !empty($url['pass']) ? ':' . $url['pass'] : '';

        $parts['pass'] = ($parts['user'] || !empty($parts['pass'])) ? $parts['pass'] . '@' : '';

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
}
