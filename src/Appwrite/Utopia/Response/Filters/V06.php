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

            case Response::MODEL_USER_LIST:
                $parsedResponse = $this->parseUserList($content);
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

            case Response::MODEL_LOCALE:
                $parsedResponse = $this->parseLocale($content);
                break;

            case Response::MODEL_COUNTRY_LIST:
                $parsedResponse = $this->parseCountryList($content);
                break;

            case Response::MODEL_PHONE_LIST:
                $parsedResponse = $this->parsePhoneList($content);
                break;

            case Response::MODEL_CONTINENT_LIST:
                $parsedResponse = $this->parseContinentList($content);
                break;

            case Response::MODEL_CURRENCY_LIST:
                $parsedResponse = $this->parseCurrencyList($content);
                break;

            case Response::MODEL_ANY :
                $parsedResponse = $content;
                break;

            default:
                throw new Exception('Recevied invalid model : '.$model);
        }

        return $parsedResponse;
    }

    private function parseProject(array $content) 
    {

    }

    private function parseCurrencyList(array  $content) 
    {
        $content['locations'] = [];
        return $content;
    }

    private function parseContinentList(array $content)
    {
        $continents = $content['continents'];
        $parsedResponse = [];
        foreach($continents as $continent) {
            $parsedResponse['code'] = $continent['name'];
        }

        return $parsedResponse;
    }

    private function parsePhoneList(array $content)
    {
        $phones = $content['phones'];
        $parsedResponse = [];
        foreach($phones as $phone) {
            $parsedResponse['countryCode'] = $phone['code'];
        }

        return $parsedResponse;
    }

    private function parseCountryList(array $content) 
    {
        $countries = $content['countries'];
        $parsedResponse = [];
        foreach($countries as $country) {
            $parsedResponse['code'] = $country['name'];
        }

        return $parsedResponse;
    }

    private function parseLocale(array $content) 
    {
        $content['ip'] = empty($content['ip']) ? '' : $content['ip'];
        $content['countryCode'] = empty($content['countryCode']) ? '--' : $content['countryCode'];
        $content['country'] = empty($content['country']) ? Locale::getText('locale.country.unknown') : $content['country'];
        $content['continent'] = empty($content['continent']) ? Locale::getText('locale.country.unknown') : $content['continent'];
        $content['continentCode'] = empty($content['continentCode']) ? '--' : $content['continentCode'];
        $content['eu'] = empty($content['eu']) ? false : $content['eu'];
        $content['currency'] = empty($content['currency']) ? null : $content['currency'];
        return $content;
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

    private function parseUserList(array $content)
    {
        $users = $content['users'];
        $parsedResponse = [];
        foreach($users as $user) {
            $parsedResponse[] = $this->parseUser($user);
        }
        return $parsedResponse;
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