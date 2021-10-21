<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Exception;

class V08 extends Filter
{
    
    // Convert 0.9 Data format to 0.8 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = [];

        switch ($model) {

            case Response::MODEL_DOCUMENT_LIST:
            case Response::MODEL_DOCUMENT:
            case Response::MODEL_USER_LIST:
            case Response::MODEL_USER:
            case Response::MODEL_COLLECTION_LIST:
            case Response::MODEL_COLLECTION:
            case Response::MODEL_FILE_LIST:
            case Response::MODEL_FILE:
            case Response::MODEL_TAG_LIST:
            case Response::MODEL_TAG:
            case Response::MODEL_EXECUTION_LIST:
            case Response::MODEL_EXECUTION:
            case Response::MODEL_TEAM_LIST:
            case Response::MODEL_TEAM:
            case Response::MODEL_MEMBERSHIP_LIST:
            case Response::MODEL_MEMBERSHIP:
            case Response::MODEL_SESSION_LIST:
            case Response::MODEL_SESSION:
            case Response::MODEL_JWT:
            case Response::MODEL_LOG:
            case Response::MODEL_LOG_LIST:
            case Response::MODEL_TOKEN:
            case Response::MODEL_LOCALE:
            case Response::MODEL_COUNTRY:
            case Response::MODEL_COUNTRY_LIST:
            case Response::MODEL_PHONE:
            case Response::MODEL_PHONE_LIST:
            case Response::MODEL_CONTINENT:
            case Response::MODEL_CONTINENT_LIST:
            case Response::MODEL_CURRENCY:
            case Response::MODEL_CURRENCY_LIST:
            case Response::MODEL_LANGUAGE:
            case Response::MODEL_LANGUAGE_LIST:
            case Response::MODEL_PROJECT:
            case Response::MODEL_PROJECT_LIST:
            case Response::MODEL_PLATFORM:
            case Response::MODEL_PLATFORM_LIST:
            case Response::MODEL_DOMAIN:
            case Response::MODEL_DOMAIN_LIST:
            case Response::MODEL_KEY:
            case Response::MODEL_KEY_LIST:
            case Response::MODEL_PERMISSIONS:
            case Response::MODEL_RULE:
            case Response::MODEL_TASK:
            case Response::MODEL_WEBHOOK:
            case Response::MODEL_WEBHOOK_LIST:
            case Response::MODEL_MOCK:
            case Response::MODEL_ANY:
            case Response::MODEL_PREFERENCES:
            case Response::MODEL_NONE:
            case Response::MODEL_ERROR:
            case Response::MODEL_ERROR_DEV:
                $parsedResponse = $content;
                break;
            case Response::MODEL_FUNCTION_LIST: /** Function property env was renamed to runtime in 0.9.x  */
                $parsedResponse = $this->parseFunctionList($content);
                break;
            case Response::MODEL_FUNCTION: /** Function property env was renamed to runtime in 0.9.x  */
                $parsedResponse = $this->parseFunctionList($content);
                break;
            default:
                throw new Exception('Received invalid model : '.$model);
        }

        return $parsedResponse;
    }

    protected function parseFunction(array $content)
    {
        $content['env'] = $content['runtime'];
        unset($content['runtime']);
        return $content;
    }

    protected function parseFunctionList(array $content)
    {
        $functions = $content['functions'];
        $parsedResponse = [];
        foreach ($functions as $function) {
            $parsedResponse[] = $this->parseFunction($function);
        }
        $content['functions'] = $parsedResponse;
        return $content;
    }
}
