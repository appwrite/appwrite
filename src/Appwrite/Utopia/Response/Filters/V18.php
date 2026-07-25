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
            Response::MODEL_EXECUTION => $this->parseExecution($content),
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_RUNTIME => $this->parseRuntime($content),
            default => $parsedResponse,
        };

        return $parsedResponse;
    }

    protected function parseExecution(array $content)
    {
        if (!empty($content['status']) && !empty($content['statusCode'])) {
            if ($content['status'] === 'completed' && $content['statusCode'] >= 400 && $content['statusCode'] < 500) {
                $content['status'] = 'failed';
            }
        }

        unset($content['scheduledAt']);
        return $content;
    }

    protected function parseFunction(array $content)
    {
        unset($content['scopes']);
        unset($content['specification']);
        return $content;
    }

    protected function parseProject(array $content)
    {
        unset($content['authMockNumbers']);
        unset($content['authSessionAlerts']);
        return $content;
    }

    protected function parseRuntime(array $content)
    {
        unset($content['key']);
        return $content;
    }
}
