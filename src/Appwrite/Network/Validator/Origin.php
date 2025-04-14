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
     * @param array<Document> $platforms
     */
    public function __construct(array $platforms)
    {
        $this->hostnames = Platform::getHostnames($platforms);
        $this->schemes = Platform::getSchemes($platforms);
    }


    /**
     * Check if Redirect URI is valid.
     * @param mixed $redirect The redirect URI.
     * @return bool
     */
    public function isValid($redirect): bool
    {
        $this->scheme = null;
        $this->host = null;

        if (!is_string($redirect) || empty($redirect)) {
            return false;
        }

        $parts = $this->parseUrl($redirect);
        $scheme = $parts['scheme'];
        $host = $parts['host'];

        if (!empty($scheme) && in_array($scheme, $this->schemes, true)) {
            return true;
        }

        if (empty($host)) {
             return true;
        } else {
             $validator = new Hostname($this->hostnames);
             if ($validator->isValid($host)) {
                 return true;
             }
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
        $host = $this->host ? '(' . htmlspecialchars($this->host) . ')' : '';

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
     * Parses a URI string to extract scheme and host.
     * Stores extracted parts in $this->scheme and $this->host.
     * @param string $uri
     * @return array{scheme: string|null, host: string|null}
     */
    protected function parseUrl(string $uri): array
    {
        if (str_ends_with($uri, '://')) {
            $uri .= 'placeholder';
        }
        $scheme = \parse_url($uri, PHP_URL_SCHEME);
        $host = \parse_url($uri, PHP_URL_HOST);
        $this->scheme = $scheme ?: null;
        $this->host = $host ?: null;
        return ['scheme' => $this->scheme,'host' => $this->host];
    }
}
