<?php

namespace Appwrite\Network\Geo;

use Utopia\CLI\Console;
use Utopia\System\System;

/**
 * DockerGeoAdapter provides geolocation data from Docker geo service
 * Falls back to MaxMind Reader if Docker service is unavailable
 */
class DockerGeoAdapter
{
    private string $dockerServiceUrl;
    private ?\MaxMind\Db\Reader $maxMindReader;
    private int $timeout;

    public function __construct(?\MaxMind\Db\Reader $maxMindReader = null, string $dockerServiceUrl = '', int $timeout = 2)
    {
        $this->maxMindReader = $maxMindReader;
        $this->timeout = $timeout;

        // Default to internal Docker network if not specified
        if (empty($dockerServiceUrl)) {
            $this->dockerServiceUrl = System::getEnv('_APP_GEO_SERVICE_URL', 'http://appwrite-geo:9501');
        } else {
            $this->dockerServiceUrl = $dockerServiceUrl;
        }
    }

    /**
     * Get geolocation record for IP
     * Tries Docker service first, falls back to MaxMind
     */
    public function get(string $ip): GeoRecord
    {
        // Try Docker geo service first
        $dockerResult = $this->getFromDockerService($ip);
        if ($dockerResult !== null) {
            return new GeoRecord($dockerResult);
        }

        // Fall back to MaxMind Reader
        if ($this->maxMindReader !== null) {
            try {
                $data = $this->maxMindReader->get($ip);
                return GeoRecord::fromMaxMind($data);
            } catch (\Exception $e) {
                Console::warning('MaxMind Reader failed: ' . $e->getMessage());
            }
        }

        // Return empty record if both fail
        return new GeoRecord(null);
    }

    /**
     * Query Docker geo service
     */
    private function getFromDockerService(string $ip): ?array
    {
        try {
            $url = rtrim($this->dockerServiceUrl, '/') . '/geoip/' . urlencode($ip);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response !== false) {
                $data = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                    // Transform Docker service response to MaxMind-compatible format
                    return $this->transformDockerResponse($data);
                }
            }
        } catch (\Throwable $e) {
            Console::warning('Docker geo service unavailable: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Transform Docker geo service response to MaxMind-compatible format
     */
    private function transformDockerResponse(array $data): array
    {
        return [
            'country' => [
                'iso_code' => $data['country_code'] ?? $data['country']['iso_code'] ?? null,
                'name' => $data['country_name'] ?? $data['country']['name'] ?? null,
            ],
            'continent' => [
                'code' => $data['continent_code'] ?? $data['continent']['code'] ?? null,
                'name' => $data['continent_name'] ?? $data['continent']['name'] ?? null,
            ],
            'city' => [
                'name' => $data['city'] ?? $data['city']['name'] ?? null,
            ],
            'postal' => [
                'code' => $data['postal_code'] ?? $data['postal']['code'] ?? null,
            ],
            'location' => [
                'latitude' => $data['latitude'] ?? $data['location']['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? $data['location']['longitude'] ?? null,
                'time_zone' => $data['time_zone'] ?? $data['location']['time_zone'] ?? null,
            ],
        ];
    }
}
