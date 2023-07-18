<?php

use Appwrite\DSN\DSN;
use Appwrite\Resque\Worker;
use Utopia\Http\Http;
use Utopia\CLI\Console;
use Utopia\Messaging\Adapter;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Messages\SMS;

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

        $message = new SMS(
            to: [$this->args['recipient']],
            content: $this->args['message'],
            from: $this->from,
        );

        try {
            $this->sms->send($message);
        } catch (\Exception $error) {
            throw new Exception('Error sending message: ' . $error->getMessage(), 500);
        }
    }

    public function shutdown(): void
    {
    }
}
