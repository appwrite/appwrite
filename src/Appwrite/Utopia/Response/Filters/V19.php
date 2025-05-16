<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V19 extends Filter
{
    // Convert 1.7 Data format to 1.6 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        $parsedResponse = match($model) {
            Response::MODEL_FUNCTION => $this->parseFunction($content),
            Response::MODEL_FUNCTION_LIST => $this->handleList($content, 'functions', fn ($item) => $this->parseFunction($item)),
            default => $parsedResponse,
        };

        return $parsedResponse;
    }

    protected function parseFunction(array $content)
    {
        $content['deployment'] = $content['deploymentId'] ?? '';
        unset($content['deploymentId']);
        return $content;
    }
}
