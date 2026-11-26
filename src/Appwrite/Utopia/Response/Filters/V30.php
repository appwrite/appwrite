<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Alert\Severity;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 2.8.0 Data format to 2.7.1 format
class V30 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', fn ($item) => $this->parseProject($item)),
            Response::MODEL_MONITOR,
            Response::MODEL_ALERT => $this->parseSeverity($content),
            Response::MODEL_MONITOR_LIST => $this->handleList($content, 'monitors', fn ($item) => $this->parseSeverity($item)),
            Response::MODEL_ALERT_LIST => $this->handleList($content, 'alerts', fn ($item) => $this->parseSeverity($item)),
            default => $content,
        };
    }

    // Older SDKs reject auth method IDs outside their enum
    protected function parseProject(array $content): array
    {
        if (isset($content['authMethods'])) {
            $content['authMethods'] = \array_values(\array_filter(
                $content['authMethods'],
                fn ($method) => ($method['$id'] ?? '') !== 'ldap',
            ));
        }

        return $content;
    }

    // `severity` replaced the `critical` flag older SDKs still read
    protected function parseSeverity(array $content): array
    {
        $content['critical'] = ($content['severity'] ?? '') === Severity::Critical->value;

        return $content;
    }
}
