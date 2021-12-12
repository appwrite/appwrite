<?php

namespace Appwrite\Detector;

use DeviceDetector\DeviceDetector;

class Detector
{
    /**
     * @param string
     */
    protected $userAgent = '';

    /**
     * @param DeviceDetector
     */
    protected $detctor;

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
        $os = $this->getDetector()->getOs();

        return [
            'osCode' => (isset($os['short_name'])) ? $os['short_name'] : '',
            'osName' => (isset($os['name'])) ? $os['name'] : '',
            'osVersion' => (isset($os['version'])) ? $os['version'] : '',
        ];
    }

    /**
     * Get client info
     *
     * @return array
     */
    public function getClient(): array
    {
        if (strpos($this->userAgent, 'Terminal') !== false) {
            $version = explode(' ', $this->userAgent)[0];
            $version = explode('/', $version)[1];
            $client = [
                'type' => 'desktop',
                'short_name' => 'terminal',
                'name' => 'Terminal',
                'version' => $version
            ];
        } else {
            $client = $this->getDetector()->getClient();
        }

        return [
            'clientType' => (isset($client['type'])) ? $client['type'] : '',
            'clientCode' => (isset($client['short_name'])) ? $client['short_name'] : '',
            'clientName' => (isset($client['name'])) ? $client['name'] : '',
            'clientVersion' => (isset($client['version'])) ? $client['version'] : '',
            'clientEngine' => (isset($client['engine'])) ? $client['engine'] : '',
            'clientEngineVersion' => (isset($client['engine_version'])) ? $client['engine_version'] : '',
        ];
    }

    /**
     * Get device info
     *
     * @return array
     */
    public function getDevice(): array
    {
        return [
            'deviceName' => $this->getDetector()->getDeviceName(),
            'deviceBrand' => $this->getDetector()->getBrandName(),
            'deviceModel' => $this->getDetector()->getModel(),
        ];
    }

    /**
     * @return DeviceDetector
     */
    protected function getDetector(): DeviceDetector
    {
        if (!$this->detctor) {
            $this->detctor = new DeviceDetector($this->userAgent);
            $this->detctor->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)
            $this->detctor->parse();
        }

        return $this->detctor;
    }
}
