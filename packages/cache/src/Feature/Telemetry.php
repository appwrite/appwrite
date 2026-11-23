<?php

declare(strict_types=1);

namespace Utopia\Cache\Feature;

use Utopia\Telemetry\Adapter as TelemetryAdapter;

interface Telemetry
{
    public function setTelemetry(TelemetryAdapter $telemetry): void;
}
