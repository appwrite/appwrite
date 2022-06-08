<?php

use Appwrite\Auth\Phone;
use Appwrite\Auth\Phone\{
    Mock,
    Telesign,
    TextMagic,
    Twilio
};
use Appwrite\Resque\Worker;
use Appwrite\Template\Template;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Locale\Locale;

require_once __DIR__ . '/../init.php';

Console::title('Messaging V1 Worker');
Console::success(APP_NAME . ' messaging worker v1 has started' . "\n");

class MessagingV1 extends Worker
{
    protected ?Phone $phone = null;
    protected ?string $from = null;

    public function getName(): string
    {
        return "mails";
    }

    public function init(): void
    {
        $provider = App::getEnv('_APP_PHONE_PROVIDER');
        $user = App::getEnv('_APP_PHONE_USER');
        $secret = App::getEnv('_APP_PHONE_SECRET');

        $this->from = App::getEnv('_APP_PHONE_FROM');
        $this->phone = match ($provider) {
            'mock' => new Mock('', ''), // used for tests
            'twilio' => new Twilio($user, $secret),
            'text-magic' => new TextMagic($user, $secret),
            'telesign' => new Telesign($user, $secret),
            default => null
        };
    }

    public function run(): void
    {
        if (empty(App::getEnv('_APP_PHONE_PROVIDER'))) {
            Console::info('Skipped sms processing. No Phone provider has been set.');
            return;
        }

        $recipient = $this->args['recipient'];
        $message = $this->args['message'];

        try {
            $this->phone->send($this->from, $recipient, $message);
        } catch (\Exception $error) {
            throw new Exception('Error sending message: ' . $error->getMessage(), 500);
        }
    }

    public function shutdown(): void
    {
    }
}
