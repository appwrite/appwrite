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
        if (strpos($this->userAgent, 'AppwriteCLI') !== false) {
            $version = explode(' ', $this->userAgent)[0];
            $version = explode('/', $version)[1];
            $client = [
                'type' => 'desktop',
                'short_name' => 'cli',
                'name' => 'Appwrite CLI',
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
        $deviceName = $this->getDetector()->getDeviceName();
        $deviceBrand = $this->getDetector()->getBrandName();
        $deviceModel = $this->getDetector()->getModel();

        return [
            'deviceName' => empty($deviceName) ? null : $deviceName,
            'deviceBrand' => empty($deviceBrand) ? null : $deviceBrand,
            'deviceModel' => empty($deviceModel) ? null : $deviceModel,
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

    /**
     * Sets whether to skip bot detection.
     * It is needed if we want bots to be processed as a simple clients. So we can detect if it is mobile client,
     * or desktop, or enything else. By default all this information is not retrieved for the bots.
     *
     * @param bool $skip
     */
    public function skipBotDetection(bool $skip = true): void
    {
        $this->getDetector()->skipBotDetection($skip);
    }
}
