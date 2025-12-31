<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Platform;
use Utopia\Validator;
use Utopia\Validator\Hostname;

class Origin extends Validator
{
    protected ?string $scheme = null;
    protected ?string $host = null;
    protected string $origin = '';

    /**
     * Constructor
     *
     * @param array<string> $allowedHostnames
     * @param array<string> $allowedSchemes
     */
    public function __construct(protected array $allowedHostnames, protected array $allowedSchemes)
    {
    }


    /**
     * Check if Origin is valid.
     * @param mixed $origin The Origin URI.
     * @return bool
     */
    public function isValid($origin): bool
    {
        $this->origin = $origin;
        $this->scheme = null;
        $this->host = null;

        if (!is_string($origin) || empty($origin)) {
            return false;
        }

        $this->scheme = $this->parseScheme($origin);
        $this->host = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');

        $webPlatforms = [
            Platform::SCHEME_HTTP,
            Platform::SCHEME_HTTPS,
            Platform::SCHEME_CHROME_EXTENSION,
            Platform::SCHEME_FIREFOX_EXTENSION,
            Platform::SCHEME_SAFARI_EXTENSION,
            Platform::SCHEME_EDGE_EXTENSION,
        ];
        if (in_array($this->scheme, $webPlatforms, true)) {
            $validator = new Hostname($this->allowedHostnames);
            return $validator->isValid($this->host);
        }

        if (!empty($this->scheme) && in_array($this->scheme, $this->allowedSchemes, true)) {
            return true;
        }

        return false;
    }

    /**
     * Get Description
     * @return string
     */
    public function getDescription(): string
    {
        $platform = $this->scheme ? Platform::getNameByScheme($this->scheme) : '';
        $host = $this->host ? '(' . $this->host . ')' : '';

        if (empty($this->host) && empty($this->scheme)) {
            return 'Invalid Origin.';
        }

        if (empty($platform)) {
            return 'Invalid Scheme. The scheme used (' . $this->scheme . ') in the Origin (' . $this->origin . ') is not supported. If you are using a custom scheme, please change it to `appwrite-callback-<PROJECT_ID>`';
        }

        return 'Invalid Origin. Register your new client ' . $host . ' as a new '
            . $platform . ' platform on your project console dashboard';
    }

    /**
     * Is array
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    /**
     * Parses the scheme from a URI string.
     *
     * @param string $uri The URI string to parse.
     * @return string|null The extracted scheme string (e.g., "http", "exp", "mailto")
     */
    public function parseScheme(string $uri): ?string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return null; // No scheme in empty string
        }

        $scheme = parse_url($uri, PHP_URL_SCHEME);
        if ($scheme === false) {
            if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $uri, $matches)) {
                return $matches[1];
            } else {
                return null;
            }
        } else {
            return $scheme;
        }
    }
}
