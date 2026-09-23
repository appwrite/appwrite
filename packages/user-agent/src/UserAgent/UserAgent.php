<?php

declare(strict_types=1);

namespace Utopia\UserAgent;

use Utopia\UserAgent\Detection\BotDetector;
use Utopia\UserAgent\Detection\ClientDetector;
use Utopia\UserAgent\Detection\DeviceDetector;
use Utopia\UserAgent\Detection\OperatingSystemDetector;

/**
 * A lazily evaluated analysis of one user-agent string.
 *
 * Each category is detected at most once. Bot detection is independent from
 * client and device detection, so bots never suppress the other results.
 */
final class UserAgent
{
    private ?OperatingSystem $operatingSystem = null;

    private ?Client $client = null;

    private ?Device $device = null;

    private ?Bot $bot = null;

    private bool $botResolved = false;

    private function __construct(private readonly string $value) {}

    public static function parse(string $value): self
    {
        return new self($value);
    }

    public function raw(): string
    {
        return $this->value;
    }

    public function operatingSystem(): OperatingSystem
    {
        return $this->operatingSystem ??= OperatingSystemDetector::detect($this->value);
    }

    public function client(): Client
    {
        return $this->client ??= ClientDetector::detect($this->value);
    }

    public function device(): Device
    {
        return $this->device ??= DeviceDetector::detect($this->value);
    }

    public function bot(): ?Bot
    {
        if (!$this->botResolved) {
            $this->bot = BotDetector::detect($this->value);
            $this->botResolved = true;
        }

        return $this->bot;
    }

    public function isBot(): bool
    {
        return $this->bot() instanceof \Utopia\UserAgent\Bot;
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
        return [
            'os' => $this->operatingSystem()->toArray(),
            'client' => $this->client()->toArray(),
            'device' => $this->device()->toArray(),
            'bot' => $this->bot()?->toArray(),
        ];
    }
}
