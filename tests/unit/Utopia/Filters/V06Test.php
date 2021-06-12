<?php

namespace Appwrite\Tests;

use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V06;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

class V06Test extends TestCase
{

    /**
     * @var Filter
     */
    protected $filter = null;

    public function setUp(): void
    {
        $this->filter = new V06();
    }

    public function testParseUser() 
    {
        $content = [
            '$id' => '5e5ea5c16897e',
            'name' => 'John Doe',
            'registration' => 1592981250,
            'status' => 0,
            'email' => 'john@appwrite.io',
            'emailVerification' => false,
            'prefs' => [
                'theme' => 'pink',
                'timezone' => 'UTC'
            ]
        ];

        Config::load('providers', __DIR__.'/../../../../app/config/providers.php');

        $model = Response::MODEL_USER;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['$id'], '5e5ea5c16897e');
        $this->assertEquals($parsedResponse['name'], 'John Doe');
        $this->assertEquals($parsedResponse['registration'], 1592981250);
        $this->assertEquals($parsedResponse['status'], 0);
        $this->assertEquals($parsedResponse['email'], 'john@appwrite.io');
        $this->assertEquals($parsedResponse['emailVerification'], false);
        $this->assertEquals($parsedResponse['prefs'], ['theme' => 'pink', 'timezone' => 'UTC']);
        $this->assertEquals($parsedResponse['status'], 0);
        $this->assertEquals($parsedResponse['roles'], Authorization::getRoles() ?? []);
    }

    public function testParseUserList() 
    {
        $content = [
            'sum' => 1,
            'users' => [
                0 => [
                    '$id' => '5e5ea5c16897e',
                    'name' => 'John Doe',
                    'registration' => 1592981250,
                    'status' => 0,
                    'email' => 'john@appwrite.io',
                    'emailVerification' => false,
                    'prefs' => [
                        'theme' => 'pink',
                        'timezone' => 'UTC'
                    ]
                ]
            ]
        ];

        Config::load('providers', __DIR__.'/../../../../app/config/providers.php');

        $model = Response::MODEL_USER_LIST;
        $parsedResponse = $this->filter->parse($content, $model);
        
        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['users'][0]['$id'], '5e5ea5c16897e');
        $this->assertEquals($parsedResponse['users'][0]['name'], 'John Doe');
        $this->assertEquals($parsedResponse['users'][0]['registration'], 1592981250);
        $this->assertEquals($parsedResponse['users'][0]['status'], 0);
        $this->assertEquals($parsedResponse['users'][0]['email'], 'john@appwrite.io');
        $this->assertEquals($parsedResponse['users'][0]['emailVerification'], false);
        $this->assertEquals($parsedResponse['users'][0]['prefs'], ['theme' => 'pink', 'timezone' => 'UTC']);
        $this->assertEquals($parsedResponse['users'][0]['status'], 0);
        $this->assertEquals($parsedResponse['users'][0]['roles'], Authorization::getRoles() ?? []);
    }

    public function testParseSession()
    {
        $content = [
            '$id' => '5e5ea5c16897e',
            'userId' => '5e5bb8c16897e',
            'expire' => 1592981250,
            'ip' => '127.0.0.1',
            'osCode' => 'Mac',
            'osName' => 'Mac',
            'osVersion' => 'Mac',
            'clientType' => 'browser',
            'clientCode' => 'CM',
            'clientName' => 'Chrome Mobile iOS',
            'clientVersion' => '84.0',
            'clientEngine' => 'WebKit',
            'clientEngineVersion' => '605.1.15',
            'deviceName' => 'smartphone',
            'deviceBrand' => 'Google',
            'deviceModel' => 'Nexus 5',
            'countryCode' => 'US',
            'countryName' => 'United States',
            'current' => true
        ];

        $model = Response::MODEL_SESSION;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['$id'], '5e5ea5c16897e');
        $this->assertEquals($parsedResponse['userId'], '5e5bb8c16897e');
        $this->assertEquals($parsedResponse['expire'], 1592981250);
        $this->assertEquals($parsedResponse['ip'], '127.0.0.1');
        $this->assertEquals($parsedResponse['osCode'], 'Mac');
        $this->assertEquals($parsedResponse['osName'], 'Mac');
        $this->assertEquals($parsedResponse['osVersion'], 'Mac');
        $this->assertEquals($parsedResponse['clientType'], 'browser');
        $this->assertEquals($parsedResponse['clientCode'], 'CM');
        $this->assertEquals($parsedResponse['clientName'], 'Chrome Mobile iOS');
        $this->assertEquals($parsedResponse['clientVersion'], '84.0');
        $this->assertEquals($parsedResponse['clientEngine'], 'WebKit');
        $this->assertEquals($parsedResponse['clientEngineVersion'], '605.1.15');
        $this->assertEquals($parsedResponse['deviceName'], 'smartphone');
        $this->assertEquals($parsedResponse['deviceBrand'], 'Google');
        $this->assertEquals($parsedResponse['deviceModel'], 'Nexus 5');
        $this->assertEquals($parsedResponse['countryCode'], 'US');
        $this->assertEquals($parsedResponse['countryName'], 'United States');
        $this->assertEquals($parsedResponse['current'], true);
        $this->assertEquals($parsedResponse['type'], Auth::TOKEN_TYPE_LOGIN);
    }

    public function testParseSessionList() 
    {
        $content = [
            'sum' => 1,
            'sessions' => [
                0 => [
                    '$id' => '5e5ea5c16897e',
                    'userId' => '5e5bb8c16897e',
                    'expire' => 1592981250,
                    'ip' => '127.0.0.1',
                    'osCode' => 'Mac',
                    'osName' => 'Mac',
                    'osVersion' => 'Mac',
                    'clientType' => 'browser',
                    'clientCode' => 'CM',
                    'clientName' => 'Chrome Mobile iOS',
                    'clientVersion' => '84.0',
                    'clientEngine' => 'WebKit',
                    'clientEngineVersion' => '605.1.15',
                    'deviceName' => 'smartphone',
                    'deviceBrand' => 'Google',
                    'deviceModel' => 'Nexus 5',
                    'countryCode' => 'US',
                    'countryName' => 'United States',
                    'current' => true
                ]
            ]
        ];

        $model = Response::MODEL_SESSION_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['sessions'][0]['$id'], '5e5ea5c16897e');
        $this->assertEquals($parsedResponse['sessions'][0]['brand'], 'Google');
        $this->assertEquals($parsedResponse['sessions'][0]['current'], true);
        $this->assertEquals($parsedResponse['sessions'][0]['device'], 'smartphone');
        $this->assertEquals($parsedResponse['sessions'][0]['ip'], '127.0.0.1');
        $this->assertEquals($parsedResponse['sessions'][0]['model'], 'Nexus 5');

        $this->assertEquals($parsedResponse['sessions'][0]['OS']['name'], 'Mac');
        $this->assertEquals($parsedResponse['sessions'][0]['OS']['platform'], '');
        $this->assertEquals($parsedResponse['sessions'][0]['OS']['short_name'], 'Mac');
        $this->assertEquals($parsedResponse['sessions'][0]['OS']['version'], 'Mac');

        $this->assertEquals($parsedResponse['sessions'][0]['client']['engine'], 'WebKit');
        $this->assertEquals($parsedResponse['sessions'][0]['client']['name'], 'Chrome Mobile iOS');
        $this->assertEquals($parsedResponse['sessions'][0]['client']['short_name'], 'CM');
        $this->assertEquals($parsedResponse['sessions'][0]['client']['type'], 'browser');
        $this->assertEquals($parsedResponse['sessions'][0]['client']['version'], '84.0');

        $this->assertEquals($parsedResponse['sessions'][0]['geo']['isoCode'], 'US');
        $this->assertEquals($parsedResponse['sessions'][0]['geo']['country'], 'United States');
    }

    public function testParseLogList()
    {
        $content = [
            'sum' => 1,
            'logs' => [
                0 => [
                    'event' => 'account.sessions.create',
                    'ip' => '127.0.0.1',
                    'time' => 1592981250,
                    'osCode' => 'Mac',
                    'osName' => 'Mac',
                    'osVersion' => 'Mac',
                    'clientType' => 'browser',
                    'clientCode' => 'CM',
                    'clientName' => 'Chrome Mobile iOS',
                    'clientVersion' => '84.0',
                    'clientEngine' => 'WebKit',
                    'clientEngineVersion' => '605.1.15',
                    'deviceName' => 'smartphone',
                    'deviceBrand' => 'Google',
                    'deviceModel' => 'Nexus 5',
                    'countryCode' => 'US',
                    'countryName' => 'United States'
                ]
            ]
        ];

        $model = Response::MODEL_LOG_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['logs'][0]['brand'], 'Google');
        $this->assertEquals($parsedResponse['logs'][0]['device'], 'smartphone');
        $this->assertEquals($parsedResponse['logs'][0]['event'], 'account.sessions.create');
        $this->assertEquals($parsedResponse['logs'][0]['ip'], '127.0.0.1');
        $this->assertEquals($parsedResponse['logs'][0]['model'], 'Nexus 5');
        $this->assertEquals($parsedResponse['logs'][0]['time'], 1592981250);

        $this->assertEquals($parsedResponse['logs'][0]['OS']['name'], 'Mac');
        $this->assertEquals($parsedResponse['logs'][0]['OS']['platform'], '');
        $this->assertEquals($parsedResponse['logs'][0]['OS']['short_name'], 'Mac');
        $this->assertEquals($parsedResponse['logs'][0]['OS']['version'], 'Mac');

        $this->assertEquals($parsedResponse['logs'][0]['client']['engine'], 'WebKit');
        $this->assertEquals($parsedResponse['logs'][0]['client']['name'], 'Chrome Mobile iOS');
        $this->assertEquals($parsedResponse['logs'][0]['client']['short_name'], 'CM');
        $this->assertEquals($parsedResponse['logs'][0]['client']['type'], 'browser');
        $this->assertEquals($parsedResponse['logs'][0]['client']['version'], '84.0');

        $this->assertEquals($parsedResponse['logs'][0]['geo']['isoCode'], 'US');
        $this->assertEquals($parsedResponse['logs'][0]['geo']['country'], 'United States');
    }

    public function testParseTeam()
    {
        $content = [
            '$id' => '5ff45ef261829',
            'name' => 'test',
            'dateCreated' => 1592981250,
            'sum' => 7
        ];

        $model = Response::MODEL_TEAM;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['$id'], '5ff45ef261829');
        $this->assertEquals($parsedResponse['name'], 'test');
        $this->assertEquals($parsedResponse['dateCreated'], 1592981250);
        $this->assertEquals($parsedResponse['sum'], 7);
        $this->assertEquals($parsedResponse['$collection'], 'teams');
        $this->assertEquals($parsedResponse['$permissions'], []);
    }

    public function testParseTeamList()
    {
        $content = [
            'sum' => 1,
            'teams' => [
                0 => [
                    '$id' => '5ff45ef261829',
                    'name' => 'test',
                    'dateCreated' => 1592981250,
                    'sum' => 7
                ]
            ]
        ];

        $model = Response::MODEL_TEAM_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['teams'][0]['$id'], '5ff45ef261829');
        $this->assertEquals($parsedResponse['teams'][0]['name'], 'test');
        $this->assertEquals($parsedResponse['teams'][0]['dateCreated'], 1592981250);
        $this->assertEquals($parsedResponse['teams'][0]['sum'], 7);
        $this->assertEquals($parsedResponse['teams'][0]['$collection'], 'teams');
        $this->assertEquals($parsedResponse['teams'][0]['$permissions'], []);

    }

    public function testParseToken()
    {
        $content = [
            '$id' => 'bb8ea5c16897e',
            'userId' => '5e5ea5c168bb8',
            'secret' => '',
            'expire' => 1592981250
        ];

        $model = Response::MODEL_TOKEN;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['$id'], 'bb8ea5c16897e');
        $this->assertEquals($parsedResponse['userId'], '5e5ea5c168bb8');
        $this->assertEquals($parsedResponse['expire'], 1592981250);
        $this->assertEquals($parsedResponse['secret'], '');
        $this->assertEquals($parsedResponse['type'], Auth::TOKEN_TYPE_RECOVERY);
    }

    public function testParseLocale()
    {
        $content = [
            'ip' => '127.0.0.1',
            'countryCode' => 'US',
            'country' => 'United States',
            'continentCode' => 'NA',
            'continent' => 'North America',
            'eu' => false,
            'currency' => 'USD'
        ];

        $model = Response::MODEL_LOCALE;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['ip'], '127.0.0.1');
        $this->assertEquals($parsedResponse['countryCode'], 'US');
        $this->assertEquals($parsedResponse['country'], 'United States');
        $this->assertEquals($parsedResponse['continentCode'], 'NA');
        $this->assertEquals($parsedResponse['continent'], 'North America');
        $this->assertEquals($parsedResponse['eu'], false);
        $this->assertEquals($parsedResponse['currency'], 'USD');
    }

    public function testParseCountryList()
    {
        $content = [
            'sum' => 1,
            'countries' => [
                0 => [
                    'name' => 'United States',
                    'code' => 'US'
                ]
            ]
        ];

        $model = Response::MODEL_COUNTRY_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['countries']['US'], 'United States');
    }

    public function testParsePhoneList()
    {
        $content = [
            'sum' => 1,
            'phones' => [
                0 => [
                    'code' => '+1',
                    'countryCode' => 'US',
                    'countryName' => 'United States'
                ]
            ]
        ];

        $model = Response::MODEL_PHONE_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['phones']['US'], '+1');
    }

    public function testParseContinentList()
    {
        $content = [
            'sum' => 1,
            'continents' => [
                0 => [
                    'name' => 'Europe',
                    'code' => 'EU',
                ]
            ]
        ];

        $model = Response::MODEL_CONTINENT_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['continents']['EU'], 'Europe');   
    }

    public function testParseCurrencyList()
    {
        $content = [
            'sum' => 1,
            'currencies' => [
                0 => [
                    'symbol' => '$',
                    'name' => 'US dollar',
                    'symbolNative' => '$',
                    'decimalDigits' => 2,
                    'rounding' => 0,
                    'code' => 'USD',
                    'namePlural' => 'US Dollars' 
                ]
            ]
        ];

        $model = Response::MODEL_CURRENCY_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['currencies'][0]['symbol'], '$'); 
        $this->assertEquals($parsedResponse['currencies'][0]['name'], 'US dollar'); 
        $this->assertEquals($parsedResponse['currencies'][0]['symbolNative'], '$'); 
        $this->assertEquals($parsedResponse['currencies'][0]['decimalDigits'], 2); 
        $this->assertEquals($parsedResponse['currencies'][0]['rounding'], 0); 
        $this->assertEquals($parsedResponse['currencies'][0]['code'], 'USD');
        $this->assertEquals($parsedResponse['currencies'][0]['namePlural'], 'US Dollars');
        $this->assertEquals($parsedResponse['currencies'][0]['locations'], []);  
    }

    public function testParseFile()
    {
        $content = [
            '$id' => '5e5ea5c16897e',
            '$permissions' => ['read' => ['role:all'], 'write' => ['role:all']],
            'name' => 'Pink.png',
            'dateCreated' => 1592981250,
            'signature' => '5d529fd02b544198ae075bd57c1762bb',
            'mimeType' => 'image/png',
            'sizeOriginal' => 17890
        ];

        $model = Response::MODEL_FILE;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['$id'], '5e5ea5c16897e');
        $this->assertEquals($parsedResponse['$permissions'], ['read' => ['role:all'], 'write' => ['role:all']]);
        $this->assertEquals($parsedResponse['name'], 'Pink.png');
        $this->assertEquals($parsedResponse['dateCreated'], 1592981250);
        $this->assertEquals($parsedResponse['signature'], '5d529fd02b544198ae075bd57c1762bb');
        $this->assertEquals($parsedResponse['mimeType'], 'image/png');
        $this->assertEquals($parsedResponse['sizeOriginal'], 17890);
        $this->assertEquals($parsedResponse['$collection'], Database::SYSTEM_COLLECTION_FILES);
        $this->assertEquals($parsedResponse['algorithm'], 'gzip');
        $this->assertEquals($parsedResponse['comment'], '');
        $this->assertEquals($parsedResponse['fileOpenSSLCipher'], OpenSSL::CIPHER_AES_128_GCM);
        $this->assertEquals($parsedResponse['fileOpenSSLIV'], '');
        $this->assertEquals($parsedResponse['fileOpenSSLTag'], '');
        $this->assertEquals($parsedResponse['fileOpenSSLVersion'], '');
        $this->assertEquals($parsedResponse['folderId'], '');
        $this->assertEquals($parsedResponse['path'], '');
        $this->assertEquals($parsedResponse['sizeActual'], $content['sizeOriginal']);
        $this->assertEquals($parsedResponse['token'], '');
    }

    public function testParseCollection()
    {
        $content = [
            '$id' => '5e5ea5c16897e',
            '$permissions' => ['read' => ['role:all'], 'write' => ['role:all']],
            'name' => 'Movies',
            'dateCreated' => 1592981250,
            'dateUpdated' => '5d529fd02b544198ae075bd57c1762bb',
            'rules' => []
        ];

        $model = Response::MODEL_COLLECTION;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['$id'], '5e5ea5c16897e');
        $this->assertEquals($parsedResponse['$permissions'], ['read' => ['role:all'], 'write' => ['role:all']]);
        $this->assertEquals($parsedResponse['name'], 'Movies');
        $this->assertEquals($parsedResponse['dateCreated'], 1592981250);
        $this->assertEquals($parsedResponse['dateUpdated'], '5d529fd02b544198ae075bd57c1762bb');
        $this->assertEquals($parsedResponse['rules'], []);
        $this->assertEquals($parsedResponse['$collection'], Database::SYSTEM_COLLECTION_COLLECTIONS);
        $this->assertEquals($parsedResponse['structure'], true);
    }

    public function testParseCollectionList()
    {

        $content = [
            'sum' => 1,
            'collections' => [
                0 => [
                    '$id' => '5e5ea5c16897e',
                    '$permissions' => ['read' => ['role:all'], 'write' => ['role:all']],
                    'name' => 'Movies',
                    'dateCreated' => 1592981250,
                    'dateUpdated' => '5d529fd02b544198ae075bd57c1762bb',
                    'rules' => []
                ]
            ]
        ];

        $model = Response::MODEL_COLLECTION_LIST;
        $parsedResponse = $this->filter->parse($content, $model);

        $this->assertEquals($parsedResponse['sum'], 1);
        $this->assertEquals($parsedResponse['collections'][0]['$id'], '5e5ea5c16897e');
        $this->assertEquals($parsedResponse['collections'][0]['$permissions'], ['read' => ['role:all'], 'write' => ['role:all']]);
        $this->assertEquals($parsedResponse['collections'][0]['name'], 'Movies');
        $this->assertEquals($parsedResponse['collections'][0]['dateCreated'], 1592981250);
        $this->assertEquals($parsedResponse['collections'][0]['dateUpdated'], '5d529fd02b544198ae075bd57c1762bb');
        $this->assertEquals($parsedResponse['collections'][0]['rules'], []);
        $this->assertEquals($parsedResponse['collections'][0]['$collection'], Database::SYSTEM_COLLECTION_COLLECTIONS);
        $this->assertEquals($parsedResponse['collections'][0]['structure'], true);
    }
}