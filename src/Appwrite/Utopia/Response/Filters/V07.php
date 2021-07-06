<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Exception;
use Utopia\Config\Config;
use Utopia\Locale\Locale as Locale;

use function PHPSTORM_META\map;

class V07 extends Filter {
    
    // Convert 0.8 Data format to 0.7 format
    public function parse(array $content, string $model): array {
        
        $parsedResponse = [];

        switch($model) {

            case Response::MODEL_DOCUMENT_LIST: /** ANY was replaced by DOCUMENT in 0.8.x but this is backward compatible with 0.7.x */
            case Response::MODEL_DOCUMENT: /** ANY was replaced by DOCUMENT in 0.8.x but this is backward compatible with 0.7.x */
            case Response::MODEL_USER_LIST: /** [FIELDS ADDED in 0.8.x] passwordUpdate */
            case Response::MODEL_USER: /** [FIELDS ADDED in 0.8.x] passwordUpdate */
            case Response::MODEL_COLLECTION_LIST:
            case Response::MODEL_COLLECTION:
            case Response::MODEL_FILE_LIST:
            case Response::MODEL_FILE:
            case Response::MODEL_FUNCTION_LIST:
            case Response::MODEL_FUNCTION:
            case Response::MODEL_TAG_LIST:
            case Response::MODEL_TAG:
            case Response::MODEL_EXECUTION_LIST:
            case Response::MODEL_EXECUTION:
            case Response::MODEL_TEAM_LIST:
            case Response::MODEL_TEAM:
            case Response::MODEL_MEMBERSHIP_LIST:
            case Response::MODEL_MEMBERSHIP:
            case Response::MODEL_SESSION_LIST: /** [FIELDS ADDED in 0.8.x] provider, providerUid, providerToken */
            case Response::MODEL_SESSION: /** [FIELDS ADDED in 0.8.x] provider, providerUid, providerToken */
            case Response::MODEL_JWT:
            case Response::MODEL_LOG_LIST:
            case Response::MODEL_TOKEN:
            case Response::MODEL_LOCALE:
            case Response::MODEL_COUNTRY_LIST:
            case Response::MODEL_PHONE_LIST:
            case Response::MODEL_CONTINENT_LIST:
            case Response::MODEL_CURRENCY_LIST:
            case Response::MODEL_LANGUAGE_LIST:
            case Response::MODEL_ANY:
            case Response::MODEL_PREFERENCES: /** ANY was replaced by PREFERENCES in 0.8.x but this is backward compatible with 0.7.x */
            case Response::MODEL_NONE:
                $parsedResponse = $content; 
                break;
            default:
                throw new Exception('Received invalid model : '.$model);
        }

        return $parsedResponse;
    }

}