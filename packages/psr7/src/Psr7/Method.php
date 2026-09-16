<?php

declare(strict_types=1);

namespace Utopia\Psr7;

final class Method
{
    public const string CONNECT = 'CONNECT';

    public const string DELETE = 'DELETE';

    public const string GET = 'GET';

    public const string HEAD = 'HEAD';

    public const string OPTIONS = 'OPTIONS';

    public const string PATCH = 'PATCH';

    public const string POST = 'POST';

    public const string PUT = 'PUT';

    public const string TRACE = 'TRACE';

    private function __construct() {}
}
