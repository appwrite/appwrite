<?php

namespace Appwrite\Geo;

use Appwrite\Locale\GeoRecord;
use Swoole\Table;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Locale\Locale;

class Geo
{
    public const CACHE_SIZE = 10_000;
    public const CACHE_VALUE_SIZE = 1024;

    public function __construct(
        private ?Client $client,
        private Locale $locale,
        private ?Table $cache = null,
    ) {
    }

    public function get(string $ip): GeoRecord
    {
        if (!\filter_var($ip, FILTER_VALIDATE_IP)) {
            Console::warning("Invalid IP address: {$ip}");
            $ip = '0.0.0.0';
        }

        $attributes = $this->cached($ip);

        if ($attributes === null) {
            $record = $this->client?->lookup($ip);
            if ($record !== null) {
                $attributes = $this->build($ip, $record);
                $this->store($ip, $attributes);
            }
        }

        return (new GeoRecord($attributes ?? $this->build($ip, [])))
            ->setLocale($this->locale);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function build(string $ip, array $record): array
    {
        $countryCode = \strtoupper($record['countryCode'] ?? '--');
        $continentCode = \strtoupper($record['continentCode'] ?? '--');

        $eu = \array_map('strtoupper', Config::getParam('locale-eu'));
        $currencies = Config::getParam('locale-currencies');
        $currency = null;

        if ($countryCode !== '--') {
            foreach ($currencies as $element) {
                if (isset($element['locations'], $element['code']) && \in_array($countryCode, $element['locations'], true)) {
                    $currency = $element['code'];
                    break;
                }
            }
        }

        $autonomousSystemNumber = $record['autonomousSystemNumber'] ?? null;

        return [
            'ip' => $ip,
            'countryCode' => $countryCode,
            'continentCode' => $continentCode,
            'eu' => $countryCode !== '--' && \in_array($countryCode, $eu, true),
            'currency' => $currency,
            'latitude' => $record['latitude'] ?? null,
            'longitude' => $record['longitude'] ?? null,
            'timeZone' => $record['timeZone'] ?? null,
            'weatherCode' => $record['weatherCode'] ?? null,
            'postalCode' => $record['postalCode'] ?? null,
            'city' => $record['city']['en'] ?? null,
            'state' => $record['subdivision']['en'] ?? $record['subdivisions'][0]['en'] ?? $record['subdivisions'][0]['names']['en'] ?? null,
            'subdivisions' => $record['subdivisions'] ?? null,
            'autonomousSystemNumber' => $autonomousSystemNumber === null ? null : (string) $autonomousSystemNumber,
            'autonomousSystemOrganization' => $record['autonomousSystemOrganization'] ?? null,
            'connectionType' => $record['connection'] ?? null,
            'connectionUsageType' => $record['user'] ?? $record['type'] ?? null,
            'connectionOrganization' => $record['organization'] ?? null,
            'isp' => $record['isp'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cached(string $ip): ?array
    {
        $value = $this->cache?->get($ip, 'value');
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $record = \json_decode($value, true);

        return \is_array($record) ? $record : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function store(string $ip, array $attributes): void
    {
        if ($this->cache === null) {
            return;
        }

        $value = \json_encode($attributes);
        if ($value === false || \strlen($value) > self::CACHE_VALUE_SIZE) {
            return;
        }

        if (!$this->cache->set($ip, ['value' => $value])) {
            // Table full — evict a slice so most hot records survive.
            $evict = (int) (self::CACHE_SIZE / 10);
            foreach ($this->cache as $key => $row) {
                $this->cache->del($key);
                if (--$evict <= 0) {
                    break;
                }
            }
            $this->cache->set($ip, ['value' => $value]);
        }
    }
}
