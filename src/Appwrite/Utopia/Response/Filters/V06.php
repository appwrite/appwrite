<?php

namespace Appwrite\Utopia\Response\Filter;

use Appwrite\Auth\Auth;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Exception;
use Utopia\Config\Config;
use Utopia\Locale\Locale as Locale;

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

            case Response::MODEL_SESSION_LIST :
                $parsedResponse = $this->parseSessionList($content);
                break;
            
            case Response::MODEL_LOG_LIST :
                $parsedResponse = $this->parseLogList($content);
                break;
            
            case Response::MODEL_TOKEN:
                $parsedResponse = $this->parseToken($content); 
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

    private function parseToken(array $content)
    {
        $content['type'] = Auth::TOKEN_TYPE_RECOVERY;
        return $content;
    }

    private function parseLogList(array $content)
    {
        $logs = $content['logs'];
        $parsedResponse = [];
        $index = 0;
        foreach($logs as $log) {
            $parsedResponse[$index++] = [
                'event' => $log['event'],
                'ip' => $log['ip'],
                'time' => strtotime($log['time']),
                'OS' => $log['osName'].' '.$log['osVersion'],
                'client' => $log['clientName'].' '.$log['clientVersion'],
                'device' => $log['deviceName'],
                'brand' => $log['deviceBrand'],
                'model' => $log['deviceModel'],
                'geo' => [
                    'isoCode' => empty($log['countryCode']) ? '---' : $log['countryCode']  ,
                    'country' => empty($log['countryName'] ) ? Locale::getText('locale.country.unknown') : $log['countryName']
                ]
            ];
        }
        return $parsedResponse;
    }

    private function parseSessionList(array $content)
    {
        $sessions = $content['sessions'];
        $parsedResponse = [];
        $index = 0;
        foreach($sessions as $session) {
            $parsedResponse[$index++] = [
                '$id' => $session['$id'],
                'OS' => $session['osName'].' '.$session['osVersion'],
                'client' => $session['clientName'].' '.$session['clientVersion'],
                'device' => $session['deviceName'],
                'brand' => $session['deviceBrand'],
                'model' => $session['deviceModel'],
                'ip' => $session['ip'],
                'current' => $session['current'],
                'geo' => [
                    'isoCode' => empty($session['countryCode']) ? '---' : $session['countryCode']  ,
                    'country' => empty($session['countryName'] ) ? Locale::getText('locale.country.unknown') : $session['countryName']
                ],
            ];
        }
        return $parsedResponse;
    }

    private function parseSession(array $content) 
    {       
        $content['type'] = Auth::TOKEN_TYPE_LOGIN;
        return $content;
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