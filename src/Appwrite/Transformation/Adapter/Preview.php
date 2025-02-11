<?php

namespace Appwrite\Transformation\Adapter;

use Appwrite\Transformation\Adapter;
use Utopia\App;
use Utopia\System\System;

class Preview extends Adapter
{
    /**
     * @param array<mixed> $traits Proxied response headers
     */
    public function isValid(array $traits): bool
    {
        $contentType = '';

        foreach ($traits as $key => $value) {
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

        // TODO: Find solution to this temporary fix
        if (App::isDevelopment() && $hostname === 'traefik') {
            $hostname = 'localhost';
        }

        $source = "{$protocol}://{$hostname}/scripts/preview.js";

        $this->output = $this->input;
        $this->output .= '<script defer src="' . $source . '"></script>';

        return true;
    }
}
