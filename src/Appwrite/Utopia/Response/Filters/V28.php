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
            default => $content,
        };
    }

    protected function parseProject(array $content): array
    {
        $content['devKeys'] = [];

        return $content;
    }
}
