<?php

namespace Appwrite\Platform\Workers;

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
    private array $geosmsDSNs = [];

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
        if (!empty($this->provider)) {
            $this->provider = new DSN($provider);
        }

        $geoProviders = App::getEnv('_APP_GEOSMS_PROVIDERS', '');
        if (!empty($geoProviders)) {
            foreach (explode(',', $geoProviders) as $geoProvider) {
                $dsn = new DSN($geoProvider);
                $this->geosmsDSNs[$dsn->getHost()] = $dsn;
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

        if (empty(App::getEnv('_APP_GEOSMS_PROVIDERS'))) {
            $sms = match ($this->dsn->getHost()) {
                'mock' => new Mock($this->dsn->getUser(), $this->dsn->getPassword()), // used for tests
                'twilio' => new Twilio($this->dsn->getUser(), $this->dsn->getPassword()),
                'text-magic' => new TextMagic($this->dsn->getUser(), $this->dsn->getPassword()),
                'telesign' => new Telesign($this->dsn->getUser(), $this->dsn->getPassword()),
                'msg91' => new Msg91($this->dsn->getUser(), $this->dsn->getPassword()),
                'vonage' => new Vonage($this->dsn->getUser(), $this->dsn->getPassword()),
                default => null
            };
        } else {
            $twilio = new Twilio($this->geosmsDSNs['twilio']->getUser(), $this->geosmsDSNs['twilio']->getPassword());
            $msg91 = new Msg91($this->geosmsDSNs['msg91']>getUser(), $this->geosmsDSNs['msg91']->getPassword());
            $sms = new GEOSMS($twilio);
            $sms->setLocal(CallingCode::INDIA, $msg91); 
        }

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
