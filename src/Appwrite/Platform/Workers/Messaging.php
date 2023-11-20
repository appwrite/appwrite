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

        $sms = self::createAdapterFromDSN($this->dsn);
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

    protected static function createAdapterFromDSN(DSN $dsn): SMSAdapter
    {
        $from = empty($dsn->getParam('from', '')) ? null : $dsn->getParam('from', '');

        switch ($dsn->getHost()) {
            case 'mock':
                return new Mock($dsn->getUser(), $dsn->getPassword());
            case 'msg91':
                $adapter = new Msg91($dsn->getUser(), $dsn->getPassword());
                $template = $dsn->getParam('template', App::getEnv('_APP_SMS_FROM', ''));
                if (!empty($template)) {
                    $adapter->setTemplate($template);
                }
                return $adapter;
            case 'telesign':
                return new Telesign($dsn->getUser(), $dsn->getPassword());
            case 'textmagic':
            case 'text-magic':
                return new TextMagic($dsn->getUser(), $dsn->getPassword(), $from);
            case 'twilio':
                return new Twilio($dsn->getUser(), $dsn->getPassword(), $from);
            case 'vonage':
                return new Vonage($dsn->getUser(), $dsn->getPassword(), $from);
            case 'geosms':
                return self::createGEOSMSAdapter($dsn);
            default:
                throw new \Exception('Unknown SMS provider: ' . $dsn->getHost());
        }
    }

    protected static function createGEOSMSAdapter(DSN $dsn): GEOSMS
    {
        $defaultAdapter = self::createAdapterFromDSN(new DSN($dsn->getParam('default', '')));
        $geosms = new GEOSMS($defaultAdapter);

        $parameters = [];
        \parse_str($dsn->getQuery(), $parameters);
        unset($parameters['default']);

        foreach ($parameters as $parameter => $nestedDSN) {
            // Extract the calling code in the format of local-callingCode
            // e.g. ?local-1=twilio://...
            $callingCodeMatches = [];
            if (\preg_match('/^local-(\d+)$/', $parameter, $callingCodeMatches) !== 1) {
                Console::warning('Ignoring invalid GEOSMS parameter: ' . $parameter);
                continue;
            }

            $dsn = null;
            try {
                $dsn = new DSN($nestedDSN);
            } catch (\Exception $e) {
                Console::warning('Ignoring invalid GEOSMS adapter DSN: ' . $nestedDSN);
                continue;
            }

            try {
                $adapter = self::createAdapterFromDSN($dsn);
                $geosms->setLocal($callingCodeMatches[1], $adapter);
            } catch (\Exception $e) {
                Console::warning('Ignoring invalid GEOSMS adapter: ' . $dsn->getHost());
            }
        }

        return $geosms;
    }
}
