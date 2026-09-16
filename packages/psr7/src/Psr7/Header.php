<?php

declare(strict_types=1);

namespace Utopia\Psr7;

final class Header
{
    public const string ACCEPT = 'Accept';

    public const string ACCEPT_ENCODING = 'Accept-Encoding';

    public const string AUTHORIZATION = 'Authorization';

    public const string CACHE_CONTROL = 'Cache-Control';

    public const string CONTENT_DISPOSITION = 'Content-Disposition';

    public const string CONTENT_ENCODING = 'Content-Encoding';

    public const string CONTENT_LENGTH = 'Content-Length';

    public const string CONTENT_TYPE = 'Content-Type';

    public const string COOKIE = 'Cookie';

    public const string DATE = 'Date';

    public const string ETAG = 'ETag';

    public const string HOST = 'Host';

    public const string IF_MATCH = 'If-Match';

    public const string IF_NONE_MATCH = 'If-None-Match';

    public const string LOCATION = 'Location';

    public const string REFERER = 'Referer';

    public const string RETRY_AFTER = 'Retry-After';

    public const string SET_COOKIE = 'Set-Cookie';

    public const string TRACEPARENT = 'traceparent';

    public const string USER_AGENT = 'User-Agent';

    private function __construct() {}
}
