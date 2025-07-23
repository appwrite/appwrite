<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Platform;
use Utopia\Validator\Hostname;

/**
 * Redirect
 *
 * Validate that a URI is allowed as a redirect destination for OAuth2 flows
 *
 * @package Appwrite\Network\Validator
 */
class Redirect extends Origin
{
    private string $redirect = '';

    /**
     * Is valid
     *
     * Validation will pass when $value matches the given hostnames or schemes.
     * Unlike Origin validator, empty values are not allowed for redirects.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        $this->redirect = $value ?? '';
        $this->origin = $this->redirect;
        $this->scheme = null;
        $this->host = null;

        // Empty values are not allowed for redirects (unlike Origin)
        if (!is_string($value) || empty($value)) {
            return false;
        }

        $this->scheme = $this->parseScheme($value);
        $this->host = strtolower(parse_url($value, PHP_URL_HOST) ?? '');

        // Check if it's a custom scheme that's allowed
        if (!empty($this->scheme) && in_array($this->scheme, $this->schemes, true)) {
            // For custom schemes like exp:// or appwrite-callback-*://, we allow them if they're in the scheme list
            $webPlatforms = [
                Platform::SCHEME_HTTP,
                Platform::SCHEME_HTTPS,
                Platform::SCHEME_CHROME_EXTENSION,
                Platform::SCHEME_FIREFOX_EXTENSION,
                Platform::SCHEME_SAFARI_EXTENSION,
                Platform::SCHEME_EDGE_EXTENSION,
            ];
            
            if (!in_array($this->scheme, $webPlatforms, true)) {
                return true; // Custom scheme that's in our allowed list
            }
        }

        // For HTTP/HTTPS schemes, validate the hostname
        if (in_array($this->scheme, ['http', 'https'], true)) {
            $validator = new Hostname($this->hostnames);
            return $validator->isValid($this->host);
        }

        return false;
    }

    /**
     * Get Description
     * @return string
     */
    public function getDescription(): string
    {
        $platform = Platform::getNameByScheme($this->scheme);
        $host = $this->host ? '(' . $this->host . ')' : '';

        if (empty($this->redirect)) {
            return 'Invalid URI.';
        }

        if (empty($this->scheme)) {
            return 'Invalid URI. Missing or invalid scheme.';
        }

        if (empty($platform)) {
            return 'Invalid URI. The scheme used (' . $this->scheme . ') in the URI (' . $this->redirect . ') is not supported. If you are using a custom scheme, please change it to `appwrite-callback-<PROJECT_ID>`';
        }

        return 'Invalid URI. Register your new client ' . $host . ' as a new '
            . $platform . ' platform on your project console dashboard';
    }
}
