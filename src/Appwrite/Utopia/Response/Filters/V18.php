<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V18 extends Filter
{
    // Convert 1.6 Data format to 1.5 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        $parsedResponse = match($model) {
            Response::MODEL_FUNCTION => $this->parseFunction($content),
            Response::MODEL_PROJECT => $this->parseProject($content),
            default => $parsedResponse,
        };

        return $parsedResponse;
    }

    protected function parseFunction(array $content)
    {
        unset($content['scopes']);
        return $content;
    }

    protected function parseProject(array $content)
    {
        unset($content['authMockNumbers']);
        unset($content['authSessionAlerts']);
        return $content;
    }
}
