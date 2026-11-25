<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Alert\Severity;
use Appwrite\Utopia\Request\Filter;

class V28 extends Filter
{
    // Convert 2.7.x params to 2.8.0
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            'monitors.createUsageMonitor',
            'monitors.updateUsageMonitor' => $this->parseCritical($content),
            default => $content,
        };
    }

    /**
     * `severity` replaced the `critical` flag, so a flag sent by an older
     * client sets the severity it meant unless a severity is sent as well.
     */
    protected function parseCritical(array $content): array
    {
        if (!\array_key_exists('critical', $content)) {
            return $content;
        }

        $critical = $content['critical'];
        unset($content['critical']);

        if ($critical === null || isset($content['severity'])) {
            return $content;
        }

        $content['severity'] = \filter_var($critical, FILTER_VALIDATE_BOOLEAN)
            ? Severity::Critical->value
            : Severity::Warning->value;

        return $content;
    }
}
