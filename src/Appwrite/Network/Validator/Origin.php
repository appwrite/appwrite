<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Platform;
use Utopia\CLI\Console;
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
        $this->host = parse_url($origin, PHP_URL_HOST);

        Console::info('Origin: ' . $origin);
        Console::info('Hostnames: ' . json_encode($this->hostnames, JSON_PRETTY_PRINT));
        Console::info('Host: ' . $this->host);
        Console::info('Schemes: ' . json_encode($this->schemes, JSON_PRETTY_PRINT));
        Console::info('Scheme: ' . $this->scheme);

        if (!empty($this->scheme) && in_array($this->scheme, $this->schemes, true)) {
            return true;
        }

        Console::info('we got here (1)');

        // if (!in_array($this->scheme, ['http', 'https'])) {
        //     return false;
        // }

        Console::info('we got here (2)');

        $validator = new Hostname($this->hostnames);
        Console::info('Valid Hostname? ' . ($validator->isValid($this->host) ? 'Yes' : 'No'));
        return $validator->isValid($this->host);
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
