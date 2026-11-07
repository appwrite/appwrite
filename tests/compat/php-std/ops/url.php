<?php

// url.*: parse_url and the PHP 8.5 URI extension (Uri\Rfc3986\Uri): the
// getters php-std implements.

return [
    'url.parse_url' => fn (array $a) => parse_url($a['url']),
    'url.parse_url_component' => fn (array $a) => parse_url($a['url'], $a['component']),
    'url.rfc3986_parse' => function (array $a) {
        $uri = \Uri\Rfc3986\Uri::parse($a['uri']);

        return $uri === null ? null : [
            'scheme' => $uri->getScheme(),
            'rawScheme' => $uri->getRawScheme(),
            'rawUserInfo' => $uri->getRawUserInfo(),
            'rawUsername' => $uri->getRawUsername(),
            'rawPassword' => $uri->getRawPassword(),
            'host' => $uri->getHost(),
            'rawHost' => $uri->getRawHost(),
            'rawPath' => $uri->getRawPath(),
            'rawQuery' => $uri->getRawQuery(),
        ];
    },
];
