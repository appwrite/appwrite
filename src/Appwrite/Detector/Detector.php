<?php

namespace Appwrite\Detector;

use Utopia\UserAgent\UserAgent;

class Detector
{
    protected string $userAgent;

    protected ?UserAgent $agent = null;

    public function __construct(string $userAgent)
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Get OS info
     *
     * @return array<string, string>
     */
    public function getOS(): array
    {
        $os = $this->getAgent()->operatingSystem();

        return [
            'osCode' => $os->code ?? '',
            'osName' => $os->name ?? '',
            'osVersion' => $os->version ?? '',
        ];
    }

    /**
     * Get client info
     *
     * @return array<string, string>
     */
    public function getClient(): array
    {
        // The CLI is not a known user agent, so it is resolved manually
        if (\str_contains($this->userAgent, 'AppwriteCLI')) {
            $version = \explode(' ', $this->userAgent)[0];
            $version = \explode('/', $version)[1] ?? '';

            return [
                'clientType' => 'desktop',
                'clientCode' => 'cli',
                'clientName' => 'Appwrite CLI',
                'clientVersion' => $version,
                'clientEngine' => '',
                'clientEngineVersion' => '',
            ];
        }

        $client = $this->getAgent()->client();

        return [
            'clientType' => $client->type ?? '',
            'clientCode' => $client->code ?? '',
            'clientName' => $client->name ?? '',
            'clientVersion' => $client->version ?? '',
            'clientEngine' => $client->engine ?? '',
            'clientEngineVersion' => $client->engineVersion ?? '',
        ];
    }

    /**
     * Get device info
     *
     * @return array<string, string|null>
     */
    public function getDevice(): array
    {
        $device = $this->getAgent()->device();

        return [
            'deviceName' => empty($device->type) ? null : $device->type,
            'deviceBrand' => empty($device->brand) ? null : $device->brand,
            'deviceModel' => empty($device->model) ? null : $device->model,
        ];
    }

    /**
     * No-op kept for call site compatibility. utopia-php/user-agent never suppresses
     * OS, client or device info for bots, so there is nothing to skip.
     */
    public function skipBotDetection(bool $skip = true): void
    {
    }

    protected function getAgent(): UserAgent
    {
        if ($this->agent === null) {
            $this->agent = UserAgent::parse($this->userAgent);
        }

        return $this->agent;
    }
}
