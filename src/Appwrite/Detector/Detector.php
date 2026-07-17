<?php

namespace Appwrite\Detector;

use Utopia\UserAgent\UserAgent;

class Detector
{
    protected string $userAgent = '';

    protected ?UserAgent $detector = null;

    /**
     * @param string $userAgent
     */
    public function __construct(string $userAgent)
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Get OS info
     *
     * @return array
     */
    public function getOS(): array
    {
        $os = $this->getDetector()->operatingSystem();

        return [
            'osCode' => $os->code ?? '',
            'osName' => $os->name ?? '',
            'osVersion' => $os->version ?? '',
        ];
    }

    /**
     * Get client info
     *
     * @return array
     */
    public function getClient(): array
    {
        if (strpos($this->userAgent, 'AppwriteCLI') !== false) {
            $version = explode(' ', $this->userAgent)[0];
            $version = explode('/', $version)[1];

            return [
                'clientType' => 'desktop',
                'clientCode' => 'cli',
                'clientName' => 'Appwrite CLI',
                'clientVersion' => $version,
                'clientEngine' => '',
                'clientEngineVersion' => '',
            ];
        }

        $client = $this->getDetector()->client();

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
     * @return array
     */
    public function getDevice(): array
    {
        $device = $this->getDetector()->device();

        return [
            'deviceName' => $device->type,
            'deviceBrand' => $device->brand,
            'deviceModel' => $device->model,
        ];
    }

    /**
     * @return UserAgent
     */
    protected function getDetector(): UserAgent
    {
        return $this->detector ??= UserAgent::parse($this->userAgent);
    }

}
