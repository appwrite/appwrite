<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

enum ParameterLocation: string
{
    case PATH = 'path';
    case QUERY = 'query';
    case HEADER = 'header';
    case COOKIE = 'cookie';
}
