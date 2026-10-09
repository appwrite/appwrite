<?php

namespace Tests\Compat\UserAgent;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Session;
use Utopia\UserAgent\Bot;
use Utopia\UserAgent\Client;
use Utopia\UserAgent\Detection\BotDetector;
use Utopia\UserAgent\Detection\ClientDetector;
use Utopia\UserAgent\Detection\DeviceDetector;
use Utopia\UserAgent\Detection\OperatingSystemDetector;
use Utopia\UserAgent\Device;
use Utopia\UserAgent\OperatingSystem;
use Utopia\UserAgent\UserAgent;

/**
 * Maps tests/compat/user-agent/spec.json operations onto utopia-php/user-agent. Glue only: no logic.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
            'agent.inspect' => fn (array $a, Session $s) => self::inspect(UserAgent::parse($a['value'])),

            'detect.operating_system' => fn (array $a, Session $s) => OperatingSystemDetector::detect($a['value'])->toArray(),
            'detect.client' => fn (array $a, Session $s) => ClientDetector::detect($a['value'])->toArray(),
            'detect.device' => fn (array $a, Session $s) => DeviceDetector::detect($a['value'])->toArray(),
            'detect.bot' => fn (array $a, Session $s) => BotDetector::detect($a['value'])?->toArray(),

            'os.new' => function (array $a, Session $s) {
                $os = new OperatingSystem($a['code'] ?? null, $a['name'] ?? null, $a['version'] ?? null);

                return ['array' => $os->toArray(), 'known' => $os->isKnown()];
            },
            'client.new' => function (array $a, Session $s) {
                $client = new Client(
                    $a['type'] ?? null,
                    $a['code'] ?? null,
                    $a['name'] ?? null,
                    $a['version'] ?? null,
                    $a['engine'] ?? null,
                    $a['engineVersion'] ?? null,
                );

                return ['array' => $client->toArray(), 'known' => $client->isKnown(), 'browser' => $client->isBrowser()];
            },
            'device.new' => function (array $a, Session $s) {
                $device = new Device($a['type'] ?? null, $a['brand'] ?? null, $a['model'] ?? null);

                return ['array' => $device->toArray(), 'known' => $device->isKnown()];
            },
            'bot.new' => fn (array $a, Session $s) => (isset($a['category'])
                ? new Bot($a['name'], $a['category'])
                : new Bot($a['name']))->toArray(),
        ];
    }

    /**
     * Everything a UserAgent reports, and whether each category is detected once.
     *
     * @return array<string, mixed>
     */
    private static function inspect(UserAgent $agent): array
    {
        return [
            'raw' => $agent->raw(),
            'array' => $agent->toArray(),
            'isBot' => $agent->isBot(),
            'osKnown' => $agent->operatingSystem()->isKnown(),
            'clientKnown' => $agent->client()->isKnown(),
            'clientBrowser' => $agent->client()->isBrowser(),
            'deviceKnown' => $agent->device()->isKnown(),
            'memoized' => $agent->operatingSystem() === $agent->operatingSystem()
                && $agent->client() === $agent->client()
                && $agent->device() === $agent->device()
                && $agent->bot() === $agent->bot(),
        ];
    }
}
