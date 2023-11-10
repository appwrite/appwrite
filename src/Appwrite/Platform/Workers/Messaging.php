<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Messaging\Adapter\SMS\SMSFactory;
use Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\DSN\DSN;
use Utopia\Messaging\Messages\Sms;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Adapters\SMS\GEOSMS;
use Utopia\Messaging\Adapters\SMS\GEOSMS\CallingCode;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Messaging extends Action
{
    private DSN $dsn;

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


        if (empty(App::getEnv('_APP_SMS_PROVIDER') && empty(App::getEnv('_APP_GEOSMS_PROVIDERS')))) {
            Console::error('Skipped sms processing. No Phone provider has been set.');
            return;
        }

        $sms = SMSFactory::createFromDSN($this->dsn);
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
}
