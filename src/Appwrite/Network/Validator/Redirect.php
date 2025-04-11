<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Client;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Validator\Host;

/**
 * Origin
 *
 * Validate that a URI is allowed as a origin
 *
 * @package Utopia\Validator
 */
class Redirect extends Origin
{
    /** @var array<Document> */
    protected array $platforms = [];

    private string $redirect = '';

    /**
     * @param array<Document>|null $platforms
     */
    public function __construct(?array $platforms = [])
    {
        $this->platforms = $platforms ?? [];
        parent::__construct($platforms);
    }

    private function getSchemes(): array
    {
        $platforms = array_filter(
            $this->platforms,
            fn ($platform) => in_array($platform['type'], [
                Client::TYPE_CUSTOM_SCHEME,
            ])
        );
        $platforms = array_filter(
            $platforms,
            fn ($platform) => !empty($platform['key'])
        );
        $schemes = array_map(fn ($platform) => $platform['key'], $platforms);

        return array_unique($schemes);
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        $parsed = $this->parseUrl($this->redirect);
        $platform = !empty($parsed['scheme']) ? Client::getName($parsed['scheme']) : '';

        if (empty($this->redirect) || empty($parsed['scheme']) || empty($platform)) {
            return 'Unsupported platform';
        }

        return "Invalid URI. Register your new client ({$this->redirect}) as a new {$platform} platform on your project console dashboard";
    }

    /**
     * Is valid
     *
     * Validation will pass when $value matches the given hostnames or schemes.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        $this->redirect = $value ?? '';
        if (empty($value)) {
            Console::log('False because origin is empty.');
            return false;
        }

        $parsed = $this->parseUrl($value);
        if (empty($parsed['scheme'])) {
            return false;
        }

        if (
            !empty($parsed['scheme']) &&
            \in_array($parsed['scheme'], $this->getSchemes())
        ) {
            Console::log('True because scheme present and in allow list.');
            return true;
        }

        if (!in_array($parsed['scheme'], ['http', 'https'])) {
            Console::log('False because scheme is not valid or http(s).');
            return false;
        }

        return parent::isValid($value);
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
