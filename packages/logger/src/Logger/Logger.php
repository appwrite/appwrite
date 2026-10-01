<?php

namespace Utopia\Logger;

use Exception;

class Logger
{
    const LIBRARY_VERSION = '0.1.0';

    const PROVIDERS = [
        'raygun',
        'sentry',
        'appSignal',
        'logOwl',
    ];

    /**
     * @var float|null
     */
    protected $samplePercent = null;

    /**
     * @var Adapter
     */
    protected Adapter $adapter;

    /**
     * Logger constructor.
     *
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Store new log. Currently, it is instantly pushed to Adapter, but in future it could pool to increase performance.
     *
     * @param  Log  $log
     * @return int
     *
     * @throws Exception
     */
    public function addLog(Log $log): int
    {
        // Validate log
        if (
            empty($log->getAction()) ||
            empty($log->getEnvironment()) ||
            empty($log->getMessage()) ||
            empty($log->getType()) ||
            empty($log->getVersion())
        ) {
            throw new Exception('Log is not ready to be pushed.');
        }

        if (! is_null($this->samplePercent)) {
            $rand = rand(1, 100);
            if ($rand >= $this->samplePercent) {
                return 0;
            }
        }

        if ($this->adapter->validate($log)) {
            // Push log
            return $this->adapter->push($log);
        }

        return 500;
    }

    /**
     * Get list of available providers
     *
     * @return string[]
     */
    public static function getProviders(): array
    {
        return Logger::PROVIDERS;
    }

    /**
     * Check if provider is available
     *
     * @param  string  $providerName
     * @return bool
     */
    public static function hasProvider(string $providerName): bool
    {
        foreach (Logger::PROVIDERS as $registeredProviderName) {
            if ($registeredProviderName === $providerName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return only a sample of the logs from this logger
     *
     * @param  float  $sample Total percentage of issues to use with 100% being 1
     * @return self
     */
    public function setSample(float $sample): self
    {
        $this->samplePercent = $sample * 100;

        return $this;
    }

    /**
     * Get the current sample value as a percentage
     *
     * @return float|null
     */
    public function getSample(): float|null
    {
        return $this->samplePercent;
    }
}
