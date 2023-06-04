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
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Messaging extends Action
{
    private ?DSN $dsn = null;
    private string $user = '';
    private string $secret = '';
    private string $provider = '';

    public static function getName(): string
    {
        return 'messaging';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->provider  = App::getEnv('_APP_SMS_PROVIDER', '');
        if (!empty($this->provider)) {
            $this->dsn = new DSN($this->provider);
            $this->user = $this->dsn->getUser();
            $this->secret = $this->dsn->getPassword();
        }

        $this
            ->desc('Messaging worker')
            ->inject('message')
            ->callback(fn($message) => $this->action($message));
    }

    /**
     * @throws Exception
     */
    public function action(Message $message): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        if (empty($payload['recipient'])) {
            throw new Exception('Missing recipient');
        }

        if (empty($payload['message'])) {
            throw new Exception('Missing message');
        }

        $sms =  match ($this->dsn->getHost()) {
            'mock' => new Mock($this->user, $this->secret), // used for tests
            'twilio' => new Twilio($this->user, $this->secret),
            'text-magic' => new TextMagic($this->user, $this->secret),
            'telesign' => new Telesign($this->user, $this->secret),
            'msg91' => new Msg91($this->user, $this->secret),
            'vonage' => new Vonage($this->user, $this->secret),
            default => null
        };

        $from = App::getEnv('_APP_SMS_FROM');

        if (empty(App::getEnv('_APP_SMS_PROVIDER'))) {
            Console::info('Skipped sms processing. No Phone provider has been set.');
            return;
        }

        if (empty($from)) {
            Console::info('Skipped sms processing. No phone number has been set.');
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
