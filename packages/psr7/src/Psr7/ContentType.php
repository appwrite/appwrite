<?php

declare(strict_types=1);

namespace Utopia\Psr7;

final class ContentType
{
    public const string FORM_URLENCODED = 'application/x-www-form-urlencoded';

    public const string HTML = 'text/html';

    public const string JSON = 'application/json';

    public const string MERGE_PATCH_JSON = 'application/merge-patch+json';

    public const string MULTIPART_FORM_DATA = 'multipart/form-data';

    public const string OCTET_STREAM = 'application/octet-stream';

    public const string PLAIN_TEXT = 'text/plain';

    public const string PROBLEM_JSON = 'application/problem+json';

    public const string XML = 'application/xml';

    private function __construct() {}
}
