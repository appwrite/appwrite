<?php

// Utopia\UserAgent\UserAgent is registered by the utopia PHP extension (crates/extension in
// appwrite/appwrite). This file only declares it for static analysis and editors; it is never loaded.

namespace Utopia\UserAgent;

/**
 * A lazily evaluated analysis of one user-agent string.
 *
 * Each category is detected at most once. Bot detection is independent from
 * client and device detection, so bots never suppress the other results.
 */
final class UserAgent
{
    private function __construct(string $value)
    {
    }

    public static function parse(string $value): self
    {
    }

    public function raw(): string
    {
    }

    public function operatingSystem(): OperatingSystem
    {
    }

    public function client(): Client
    {
    }

    public function device(): Device
    {
    }

    public function bot(): ?Bot
    {
    }

    public function isBot(): bool
    {
    }

    /**
     * @return array{
     *     os: array{code: ?string, name: ?string, version: ?string},
     *     client: array{type: ?string, code: ?string, name: ?string, version: ?string, engine: ?string, engineVersion: ?string},
     *     device: array{type: ?string, brand: ?string, model: ?string},
     *     bot: array{name: string, category: string}|null
     * }
     */
    public function toArray(): array
    {
    }
}
