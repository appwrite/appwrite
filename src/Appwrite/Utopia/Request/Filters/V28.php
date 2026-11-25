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
     * `severity` replaced the `critical` flag. Only an explicit `true` from an
     * older client sets a severity, unless one is sent as well; any other value
     * is dropped so an update cannot downgrade an existing severity.
     */
    protected function parseCritical(array $content): array
    {
        if (!\array_key_exists('critical', $content)) {
            return $content;
        }

        $critical = $content['critical'];
        unset($content['critical']);

        if ($critical === true && !isset($content['severity'])) {
            $content['severity'] = Severity::Critical->value;
        }

        return $content;
    }
}
