<?php

namespace Appwrite\Utopia\Response\Filter;

use Appwrite\Database\Validator\Authorization;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Exception;
use Utopia\Config\Config;

class V06 extends Filter {
    
    // Convert 0.7 Data format to 0.6 format
    public function parse(array $content, string $model): array {

        $parsedResponse = array();

        switch($model) {            
            case Response::MODEL_PROJECT :
                $parsedResponse = $this->parseProject($content);
                break;

            case Response::MODEL_USER :
                $parsedResponse = $this->parseUser($content);
                break;

            default:
                throw new Exception('Recevied invlaid model : '.$model);
        }

        return $parsedResponse;
    }

    private function parseProject(array $content) 
    {

    }

    private function parseUser(array $content){
        $parsedContent = [];
        $parsedContent['$id'] = $content['$id'] ?? '';
        $parsedContent['registration'] = $content['registration'] ?? '';
        $parsedContent['name'] = $content['name'] ?? '';
        $parsedContent['email'] = $content['email'] ?? '';

        foreach (Config::getParam('providers') as $key => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $parsedContent['oauth2'.ucfirst($key)] = '';
            $parsedContent['oauth2'.ucfirst($key).'AccessToken'] = '';
        }

        $parsedContent['roles'] = Authorization::getRoles();
        return $parsedContent;
    }
}