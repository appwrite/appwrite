<?php

namespace Appwrite\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapters\SMS;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Adapters\SMS\GEOSMS;
use Utopia\Messaging\Adapters\SMS\GEOSMS\CallingCode;
use Utopia\DSN\DSN;



class SMSFactory 
{
    public static function createFromDSN(DSN $dsn): SMS
    {
        $adapter = null;

        switch ($dsn->getHost()) {
            case 'mock':
                $adapter = new Mock($dsn->getUser(), $dsn->getPassword());
                break;
            case 'msg91':
                $adapter = new Msg91($dsn->getUser(), $dsn->getPassword());
                $adapter->setTemplate($dsn->getParam('template', ''));
                break;
            case 'telesign':
                $adapter = new Telesign($dsn->getUser(), $dsn->getPassword());
                break;
            case 'textmagic':
                $adapter = new TextMagic($dsn->getUser(), $dsn->getPassword());
                break;
            case 'twilio':
                $adapter = new Twilio($dsn->getUser(), $dsn->getPassword());
                break;
            case 'vonage':
                $adapter = new Vonage($dsn->getUser(), $dsn->getPassword());
                break;
            case 'geosms':
                $adapter = SMSFactory::createGEOSMS($dsn);
                break;
        }

        return $adapter;
    }

    protected static function createGEOSMS(DSN $dsn) {

        $defaultParam = $dsn->getParam('default', '');
        $defaultDSN = new DSN($defaultParam);
        $defaultAdapter = SMSFactory::createFromDSN($defaultDSN);

        $geosms = new GEOSMS($defaultAdapter);

        foreach (CallingCode::getCodes() as $callingCode) {
            $paramKey = "local[{$callingCode}]";
            $param = $dsn->getParam($paramKey, '');

            if (empty($param)) {
                continue;
            }

            $dsn = null;
            try {
                $dsn = new DSN($param);
            } catch (\Exception $e) {
                continue;
            }

            $geosms->setLocal($callingCode, SMSFactory::createFromDSN($dsn));
        }

        return $geosms;
    }
}