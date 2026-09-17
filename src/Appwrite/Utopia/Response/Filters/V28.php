<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 2.3.0 Data format to 2.2.0 format
class V28 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', fn ($item) => $this->parseProject($item)),
            Response::MODEL_EXECUTION => $this->parseExecution($content),
            Response::MODEL_EXECUTION_LIST => $this->handleList($content, 'executions', fn ($item) => $this->parseExecution($item)),
            default => $content,
        };
    }

    protected function parseProject(array $content): array
    {
        $content['devKeys'] = [];

        return $content;
    }

    protected function parseExecution(array $content): array
    {
        // 2.2.0 reported domain-routed executions as `http`, so collapse the
        // new value back rather than handing older clients an unknown enum.
        if (($content['trigger'] ?? '') === 'domain') {
            $content['trigger'] = 'http';
        }

        return $content;
    }
}
