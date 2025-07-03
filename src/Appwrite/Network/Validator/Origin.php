<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Platform;
use Utopia\Validator;
use Utopia\Validator\Hostname;

class Origin extends Validator
{
    protected array $hostnames = [];
    protected array $schemes = [];
    protected ?string $scheme = null;
    protected ?string $host = null;

    /**
     * Constructor
     *
     * @param array<\Utopia\Database\Document> $platforms
     */
    public function __construct(array $platforms)
    {
        $this->hostnames = Platform::getHostnames($platforms);
        $this->schemes = Platform::getSchemes($platforms);
    }


    /**
     * Check if Origin is valid.
     * @param mixed $origin The Origin URI.
     * @return bool
     */
    public function isValid($origin): bool
    {
        $this->scheme = null;
        $this->host = null;

        if (!is_string($origin) || empty($origin)) {
            return false;
        }

        $this->scheme = $this->parseScheme($origin);
        $this->host = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');

        $validator = new Hostname($this->hostnames);
        if (in_array($this->scheme, ['http', 'https']) && $validator->isValid($this->host)) { // Valid HTTP/HTTPS origin
            return true;
        }

        if (!empty($this->scheme) && in_array($this->scheme, $this->schemes, true)) { // Valid scheme-based origin
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
        $platform = $this->scheme ? Platform::getNameByScheme($this->scheme) : null;
        $host = $this->host ? '(' . $this->host . ')' : '';

        if (empty($this->host) && empty($this->scheme)) {
            return 'Invalid Origin.';
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
