<?php

namespace Appwrite\Platform\Modules\Sites\Transformers;

use Utopia\App;
use Utopia\System\System;

class Preview
{
    public function __construct(protected string $body)
    {
    }

    /**
     * Check if the transformer is recommended based on response details
     *
     * @param array<string, string> $headers
     */
    public static function isValid(array $headers): bool
    {
        $contentType = '';

        foreach ($headers as $key => $value) {
            if (\strtolower($key) === 'content-type') {
                $contentType = $value;
                break;
            }
        }

        if (\str_contains($contentType, 'text/html')) {
            return true;
        }

        return false;
    }

    public function transform(): bool
    {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_DOMAIN');

        // TODO: Temporary fix for development
        if (App::isDevelopment()) {
            $hostname = 'localhost';
        }

        $source = "{$protocol}://{$hostname}/scripts/preview.js";

        $this->body .= '<script defer src="' . $source . '"></script>';

        return true;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
