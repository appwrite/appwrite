<?php

declare(strict_types=1);

namespace Utopia\OpenAPI;

enum Version: string
{
    case V2 = '2.0';
    case V3_0 = '3.0';
    case V3_1 = '3.1';

    public static function fromDocumentVersion(string $version): self
    {
        if ($version === '2.0') {
            return self::V2;
        }

        if (preg_match('/^3\.0(?:\.\d+)?$/D', $version) === 1) {
            return self::V3_0;
        }

        if (preg_match('/^3\.1(?:\.\d+)?$/D', $version) === 1) {
            return self::V3_1;
        }

        throw new Exception\UnsupportedVersion("Unsupported OpenAPI version: {$version}");
    }
}
