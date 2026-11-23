<?php

declare(strict_types=1);

namespace Utopia\Client;

/**
 * Transport-agnostic minimum TLS protocol version. Each adapter maps these
 * to its native representation (cURL CURLOPT_SSLVERSION, Swoole ssl_protocols).
 */
enum Tls
{
    case V1_0;

    case V1_1;

    case V1_2;

    case V1_3;
}
