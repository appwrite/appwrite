<?php

namespace Appwrite\Extend;

use Utopia\Console;
use Utopia\Database\DateTime;
use Utopia\System\System;

/**
 * Opt-in trace logging for a single project+function pair via env (development / debugging).
 * Set both _APP_TRACE_PROJECT_ID and _APP_TRACE_FUNCTION_ID to enable.
 */
class TraceFunctionExecution
{
    private static ?string $cachedProjectId = null;

    private static ?string $cachedFunctionId = null;

    public static function isEnabled(): bool
    {
        return self::traceProjectId() !== '' && self::traceFunctionId() !== '';
    }

    private static function traceProjectId(): string
    {
        if (self::$cachedProjectId === null) {
            self::$cachedProjectId = System::getEnv('_APP_TRACE_PROJECT_ID', '69ddf60f001255461d5c');
        }

        return self::$cachedProjectId;
    }

    private static function traceFunctionId(): string
    {
        if (self::$cachedFunctionId === null) {
            self::$cachedFunctionId = System::getEnv('_APP_TRACE_FUNCTION_ID', '69ddf6500032ee9f5b0f');
        }

        return self::$cachedFunctionId;
    }

    public static function matches(?string $projectId, ?string $functionId): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        return (string) $projectId === self::traceProjectId()
            && (string) $functionId === self::traceFunctionId();
    }

    /**
     * @param array<string, mixed> $context Must include projectId and functionId for filtering.
     */
    public static function log(string $station, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $projectId = $context['projectId'] ?? null;
        $functionId = $context['functionId'] ?? null;
        if (!self::matches($projectId, $functionId)) {
            return;
        }

        $payload = \array_merge([
            'station' => $station,
            'time' => DateTime::now(),
        ], $context);

        Console::log('[execution-trace] ' . \json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
