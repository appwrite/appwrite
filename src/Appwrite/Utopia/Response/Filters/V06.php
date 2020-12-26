<?php

namespace Appwrite\Utopia\Response\Filter;

use Appwrite\Auth\Auth;
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

            case Response::MODEL_SESSION :
                $parsedResponse = $this->parseSession($content);
                break;
            
            case Response::MODEL_ANY :
                $parsedResponse = $content;
                break;

            default:
                throw new Exception('Recevied invlaid model : '.$model);
        }

        return $parsedResponse;
    }

    private function parseProject(array $content) 
    {

    }

    private function parseSession(array $content) 
    {
        // Handle list of sessions
        if (isset($content['sum'])) {
            $sessions = $content['sessions'];

            $parsedResponse = [];
            foreach($sessions as $session) {
                
                // WIP
                // $parsedResponse['$id'] = $token->getId();
                // $parsedResponse['OS'] = $dd->getOs();
                // $parsedResponse['client'] = $dd->getClient();
                // $parsedResponse['device'] = $dd->getDevice();
                // $parsedResponse['brand'] = $dd->getBrand();
                // $parsedResponse['model'] = $dd->getModel();
                // $parsedResponse['ip'] = $token->getAttribute('ip', '');
                // $parsedResponse['geo'] = [];
                // $parsedResponse['current'] = ($current == $token->getId()) ? true : false;
                // $parsedResponse[$index]['geo']['isoCode'] = '--';
                // $parsedResponse[$index]['geo']['country'] = Locale::getText('locale.country.unknown');

                $parsedResponse[] = $session;
            }
            return $parsedResponse;
        } else {
            // Handle single session 
            $content['type'] = Auth::TOKEN_TYPE_LOGIN;
            return $content;
        }
    }

    private function parseUser(array $content)
    {
        foreach (Config::getParam('providers') as $key => $provider) {
            if (!$provider['enabled']) {
                continue;
            }
            $content['oauth2'.ucfirst($key)] = '';
            $content['oauth2'.ucfirst($key).'AccessToken'] = '';
        }

        $content['roles'] = Authorization::getRoles() ?? [];
        return $content;
    }
}