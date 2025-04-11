<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Host;
use Utopia\Database\Document;
use Appwrite\Network\Client;

/**
 * Origin
 *
 * Validate that a URI is allowed as a origin
 *
 * @package Utopia\Validator
 */
class Origin extends Host
{
    /** @var array<Document> */
    protected array $platforms = [];

    private string $origin = "";

    /**
     * @param array<Document> $platforms
     */
    public function __construct(array $platforms)
    {
        $this->platforms = $platforms;
        parent::__construct($this->getHostnames());
    }

    private function getHostnames(): array
    {
        $platforms = array_filter(
            $this->platforms,
            fn($platform) => in_array($platform["type"], [
                Client::TYPE_WEB,
                Client::TYPE_FLUTTER_WEB,
            ])
        );
        $platforms = array_filter(
            $platforms,
            fn($platform) => !empty($platform["hostname"])
        );
        $hostnames = array_map(
            fn($platform) => $platform["hostname"],
            $platforms
        );

        return array_unique($hostnames);
    }

    private function getSchemes(): array
    {
        $platforms = array_filter(
            $this->platforms,
            fn($platform) => in_array($platform["type"], [
                Client::TYPE_CUSTOM_SCHEME,
            ])
        );
        $platforms = array_filter(
            $platforms,
            fn($platform) => !empty($platform["key"])
        );
        $schemes = array_map(fn($platform) => $platform["key"], $platforms);

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
         $parsed = $this->parseUrl($this->origin);
         $platform = !empty($parsed["scheme"]) ? Client::getName($parsed["scheme"]) : "";

         if (empty($this->origin) || empty($parsed["scheme"]) || empty($platform)) {
             return "Unsupported platform";
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
        $this->origin = $value;
        if (empty($value)) {
            return false;
        }
        $parsed = $this->parseUrl($value);

        if (
            !empty($parsed["scheme"]) &&
            \in_array($parsed["scheme"], $this->getSchemes())
        ) {
            return true;
        }

        if (!in_array($parsed["scheme"], ["http", "https"])) {
            return false;
        }

        if (
            !empty($parsed["host"]) &&
            \in_array($parsed["host"], $this->getHostnames())
        ) {
            return parent::isValid($value);
        }

        return false;
    }

    private function parseUrl($value): array
    {
        $parsed = \parse_url($value);
        $matches = [];

        $parsed["scheme"] =
            $parsed["scheme"] ??
            (preg_match("/^([a-zA-Z][a-zA-Z0-9+.-]*):\/\//", $value, $matches)
                ? $matches[1]
                : null);

        return $parsed;
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
