<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 2.4.0 Data format to 2.3.0 format
class V29 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', fn ($item) => $this->parseProject($item)),
            default => $content,
        };
    }

    // Older SDKs reject auth method IDs outside their enum
    protected function parseProject(array $content): array
    {
        if (isset($content['authMethods'])) {
            $content['authMethods'] = \array_values(\array_filter(
                $content['authMethods'],
                fn ($method) => ($method['$id'] ?? '') !== 'passkey',
            ));
        }

        return $content;
    }
}
