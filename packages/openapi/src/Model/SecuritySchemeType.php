<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

enum SecuritySchemeType: string
{
    case API_KEY = 'apiKey';
    case HTTP = 'http';
    case OAUTH2 = 'oauth2';
    case OPEN_ID_CONNECT = 'openIdConnect';
    case MUTUAL_TLS = 'mutualTLS';
}
