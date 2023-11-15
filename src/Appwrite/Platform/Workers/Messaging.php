<?php

namespace Appwrite\Platform\Workers;



use Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\DSN\DSN;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Adapters\SMS as SMSAdapter;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Adapters\SMS\GEOSMS;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Messaging extends Action
{
    private ?DSN $dsn = null;

    public static function getName(): string
    {
        return 'messaging';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $provider = App::getEnv('_APP_SMS_PROVIDER', '');
        if (!empty($provider)) {
            $this->dsn = new DSN($provider);
        }

        $this
            ->desc('Messaging worker')
            ->inject('message')
            ->callback(fn($message) => $this->action($message));
    }

    /**
     * @param Message $message
     * @return void
     * @throws Exception
     */
    public function action(Message $message): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            Console::error('Payload arg not found');
            return;
        }

        if (empty($payload['recipient'])) {
            Console::error('Recipient arg not found');
            return;
        }

        if (empty($payload['message'])) {
            Console::error('Message arg not found');
            return;
        }


        if (empty(App::getEnv('_APP_SMS_PROVIDER'))) {
            Console::error('Skipped sms processing. No Phone provider has been set.');
            return;
        }

        $sms = self::createFromDSN($this->dsn);
        $from = App::getEnv('_APP_SMS_FROM');

        if (empty($from)) {
            Console::error('Skipped sms processing. No phone number has been set.');
            return;
        }

        $message = new SMS(
            to: [$payload['recipient']],
            content: $payload['message'],
            from: $from,
        );

        try {
            $sms->send($message);
        } catch (\Exception $error) {
            throw new Exception('Error sending message: ' . $error->getMessage(), 500);
        }
    }

    protected static function createFromDSN(DSN $dsn): SMSAdapter
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
                $adapter = self::createGEOSMS($dsn);
                break;
        }

        return $adapter;
    }

    protected static function createGEOSMS(DSN $dsn): GEOSMS
    {
        $defaultDSN = new DSN($dsn->getParam('default', ''));
        $geosms = new GEOSMS(self::createFromDSN($defaultDSN));

        $geosmsConfig = [];
        \parse_str($dsn->getQuery(), $geosmsConfig);

        foreach ($geosmsConfig as $key => $nestedDSN) {
            // Extract the calling code in the format of local[callingCode]
            // e.g. local[1] = twilio://...
            $matches = [];
            if (\preg_match('/^local\[[0-9]+\]$/', $key, $matches) !== 1) {
                continue;
            }
            $callingCode = $matches[1];

            $dsn = null;
            try {
                $dsn = new DSN($nestedDSN);
            } catch (\Exception) {
                continue;
            }

            $geosms->setLocal($callingCode, self::createFromDSN($dsn));
        }

        return $geosms;
    }
}
