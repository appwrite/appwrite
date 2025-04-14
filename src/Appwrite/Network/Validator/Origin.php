<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Client;
use Utopia\Database\Document;
use Utopia\Validator;
use Utopia\Validator\Hostname;

/**
 * Origin
 *
 * Validate that a URI is allowed as a origin
 *
 * @package Utopia\Validator
 */
class Origin extends Validator
{
    /** @var array<Document> */
    protected array $platforms = [];

    private string $origin = '';

    /**
     * @param array<Document>|null $platforms
     */
    public function __construct(?array $platforms = [])
    {
        $this->platforms = $platforms ?? [];
    }

    private function getHostnames(): array
    {
        $platforms = array_filter(
            $this->platforms,
            fn ($platform) => in_array($platform['type'], [
                Client::TYPE_WEB,
                Client::TYPE_FLUTTER_WEB,
            ])
        );
        $platforms = array_filter(
            $platforms,
            fn ($platform) => !empty($platform['hostname'])
        );
        $hostnames = array_map(
            fn ($platform) => $platform['hostname'],
            $platforms
        );

        return array_unique($hostnames);
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
        $parsed = $this->parseUrl($this->origin);
        $platform = !empty($parsed['scheme']) ? Client::getName($parsed['scheme']) : '';

        if (empty($this->origin) || empty($parsed['scheme']) || empty($platform)) {
            return 'Unsupported platform';
        }

        return "Invalid Origin. Register your new client ({$this->origin}) as a new {$platform} platform on your project console dashboard";
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
        $this->origin = $value ?? '';
        if (empty($value)) {
            return true;
        }

        $validator = new Hostname($this->getHostnames());
        return $validator->isValid($value);
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
