<?php

use Appwrite\SMS\Adapter;
use Appwrite\SMS\Adapter\Mock;
use Appwrite\SMS\Adapter\Telesign;
use Appwrite\SMS\Adapter\TextMagic;
use Appwrite\SMS\Adapter\Twilio;
use Appwrite\SMS\Adapter\Msg91;
use Appwrite\SMS\Adapter\Vonage;
use Appwrite\DSN\DSN;
use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;

require_once __DIR__ . '/../init.php';

Console::title('Messaging V1 Worker');
Console::success(APP_NAME . ' messaging worker v1 has started' . "\n");

class MessagingV1 extends Worker
{
    protected ?Adapter $sms = null;
    protected ?string $from = null;

    public function getName(): string
    {
        return "mails";
    }

    public function init(): void
    {
        $dsn = new DSN(App::getEnv('_APP_SMS_PROVIDER'));
        $user = $dsn->getUser();
        $secret = $dsn->getPassword();

        $this->sms = match ($dsn->getHost()) {
            'mock' => new Mock($user, $secret), // used for tests
            'twilio' => new Twilio($user, $secret),
            'text-magic' => new TextMagic($user, $secret),
            'telesign' => new Telesign($user, $secret),
            'msg91' => new Msg91($user, $secret),
            'vonage' => new Vonage($user, $secret),
            default => null
        };

        $this->from = App::getEnv('_APP_SMS_FROM');
    }

    public function run(): void
    {
        if (empty(App::getEnv('_APP_SMS_PROVIDER'))) {
            Console::info('Skipped sms processing. No Phone provider has been set.');
            return;
        }

        if (empty($this->from)) {
            Console::info('Skipped sms processing. No phone number has been set.');
            return;
        }

        $recipient = $this->args['recipient'];
        $message = $this->args['message'];

        try {
            $this->sms->send($this->from, $recipient, $message);
        } catch (\Exception $error) {
            throw new Exception('Error sending message: ' . $error->getMessage(), 500);
        }
    }

    public function shutdown(): void
    {
    }
}
