<?php

// url.*: parse_url and the PHP 8.5 URI extension (Uri\Rfc3986\Uri).

return [
    'url.parse_url' => fn (array $a) => parse_url($a['url']),
    'url.parse_url_component' => fn (array $a) => parse_url($a['url'], $a['component']),
    'url.rfc3986_parse' => function (array $a) {
        $uri = \Uri\Rfc3986\Uri::parse($a['uri']);

        return $uri === null ? null : [
            'scheme' => $uri->getScheme(),
            'rawScheme' => $uri->getRawScheme(),
            'userInfo' => $uri->getUserInfo(),
            'rawUserInfo' => $uri->getRawUserInfo(),
            'username' => $uri->getUsername(),
            'rawUsername' => $uri->getRawUsername(),
            'password' => $uri->getPassword(),
            'rawPassword' => $uri->getRawPassword(),
            'host' => $uri->getHost(),
            'rawHost' => $uri->getRawHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
            'rawPath' => $uri->getRawPath(),
            'query' => $uri->getQuery(),
            'rawQuery' => $uri->getRawQuery(),
            'fragment' => $uri->getFragment(),
            'rawFragment' => $uri->getRawFragment(),
            'string' => $uri->toString(),
            'rawString' => $uri->toRawString(),
        ];
    },
];
