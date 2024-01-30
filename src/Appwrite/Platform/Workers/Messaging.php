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
    private array $dsns = [];

    public static function getName(): string
    {
        return 'messaging';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $providers = App::getEnv('_APP_SMS_PROVIDER', '');

        if (!empty($providers)) {
            $providers = explode(',', $providers);

            foreach ($providers as $provider) {
                $this->dsns[] = new DSN($provider);
            }
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

        if (empty($payload['project'])) {
            throw new Exception('Project not set in payload');
        }

        Console::log($payload['project']['$id']);
        $denyList = App::getEnv('_APP_SMS_PROJECTS_DENY_LIST', '');
        $denyList = explode(',', $denyList);
        if (in_array($payload['project']['$id'], $denyList)) {
            Console::error("Project is in the deny list. Skipping ...");
            return;
        }

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

        $sms = count($this->dsns) > 1
            ? self::createGEOSMSAdapter($this->dsns)
            : self::createAdapterFromDSN($this->dsns[0]);

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
            default:
                throw new \Exception('Unknown SMS provider: ' . $dsn->getHost());
        }
    }

    protected static function createGEOSMSAdapter(array $dsns): GEOSMS
    {
        $defaultAdapter = self::createAdapterFromDSN($dsns[0]);
        $geosms = new GEOSMS($defaultAdapter);
        $localDSNs = array_slice($dsns, 1);

        /** @var DSN $localDSN */
        foreach ($localDSNs as $localDSN) {
           
            $localAdapter = null;
            try {
                $localAdapter = self::createAdapterFromDSN($localDSN);
            } catch (\Exception) {
                Console::warning('Unable to create adapter: ' . $localDSN->getHost());
                continue;
            }

            $callingCode = $localDSN->getParam('local', '');
            if (empty($callingCode)) {
                Console::warning('Unable to register adapter: ' . $localDSN->getHost() . '. Missing `local` parameter.');
                continue;
            }

            $geosms->setLocal($callingCode, $localAdapter);
        }

        return $geosms;
    }
}
