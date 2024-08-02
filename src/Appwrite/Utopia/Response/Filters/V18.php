<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Database\Status as DatabaseStatus;
use Appwrite\Functions\Status as FunctionStatus;
use Appwrite\Messaging\Status as MessagingStatus;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V18 extends Filter
{
    // Convert 1.6 Data format to 1.5 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        $parsedResponse = match ($model) {
            Response::MODEL_FUNCTION => $this->parseFunction($content),
            Response::MODEL_EXECUTION => $this->parseExecution($content),
            Response::MODEL_BUILD => $this->parseBuild($content),
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_MESSAGE => $this->parseMessage($content),
            Response::MODEL_INDEX,
            Response::MODEL_ATTRIBUTE,
            Response::MODEL_ATTRIBUTE_IP,
            Response::MODEL_ATTRIBUTE_URL,
            Response::MODEL_ATTRIBUTE_ENUM,
            Response::MODEL_ATTRIBUTE_EMAIL,
            Response::MODEL_ATTRIBUTE_FLOAT,
            Response::MODEL_ATTRIBUTE_INTEGER,
            Response::MODEL_ATTRIBUTE_BOOLEAN,
            Response::MODEL_ATTRIBUTE_DATETIME,
            Response::MODEL_ATTRIBUTE_RELATIONSHIP, => $this->parseAttribute($content),
            default => $parsedResponse,
        };

        return $parsedResponse;
    }

    protected function parseBuild(array $content)
    {
        $content['status'] = FunctionStatus::getV18status($content['status']);

        return $content;
    }


    protected function parseExecution(array $content)
    {
        $content['status'] = FunctionStatus::getV18status($content['status']);
        unset($content['scheduledAt']);
        return $content;
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

    protected function parseMessage(array $content)
    {
        $content['status'] = MessagingStatus::getV18status($content['status']);

        return $content;
    }

    private function parseAttribute(array $content)
    {
        $content['status'] = DatabaseStatus::getV18status($content['status']);

        return $content;
    }
}
