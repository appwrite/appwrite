<?php

namespace Appwrite\Cache;

use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;

class CircuitBreakerFactory
{
    private const THRESHOLD = 3;
    private const TIMEOUT = 30;
    private const SUCCESS_THRESHOLD = 2;
    private const METRIC_PREFIX = 'appwrite';

    public static function create(?Telemetry $telemetry = null, string $metricPrefix = self::METRIC_PREFIX): CircuitBreaker
    {
        return new CircuitBreaker(
            threshold: self::THRESHOLD,
            timeout: self::TIMEOUT,
            successThreshold: self::SUCCESS_THRESHOLD,
            telemetry: $telemetry,
            metricPrefix: $metricPrefix,
        );
    }
}
