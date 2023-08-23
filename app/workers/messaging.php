<?php

use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Messaging\Adapters\Email as EmailAdapter;
use Utopia\Messaging\Adapters\Email\Mailgun;
use Utopia\Messaging\Adapters\Email\SendGrid;
use Utopia\Messaging\Adapters\Push\APNS;
use Utopia\Messaging\Adapters\Push as PushAdapter;
use Utopia\Messaging\Adapters\Push\FCM;
use Utopia\Messaging\Adapters\SMS as SMSAdapter;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;

require_once __DIR__.'/../init.php';

Console::title('Messaging V1 Worker');
Console::success(APP_NAME.' messaging worker v1 has started'."\n");

class MessagingV1 extends Worker
{
    protected ?SMSAdapter $sms = null;

    protected ?PushAdapter $push = null;

    protected ?EmailAdapter $email = null;

    protected ?string $from = null;

    public function getName(): string
    {
        return 'mails';
    }

    public function sms($record): ?SMSAdapter
    {
        $credentials = $record->getAttribute('credentials');

        return match ($record->getAttribute('provider')) {
            'twilio' => new Twilio($credentials['accountSid'], $credentials['authToken']),
            'text-magic' => new TextMagic($credentials['username'], $credentials['apiKey']),
            'telesign' => new Telesign($credentials['username'], $credentials['password']),
            'msg91' => new Msg91($credentials['senderId'], $credentials['authKey']),
            'vonage' => new Vonage($credentials['apiKey'], $credentials['apiSecret']),
            default => null
        };
    }

    public function push($record): ?PushAdapter
    {
        $credentials = $record->getAttribute('credentials');

        return match ($record->getAttribute('provider')) {
            'apns' => new APNS(
                $credentials['authKey'],
                $credentials['authKeyId'],
                $credentials['teamId'],
                $credentials['bundleId'],
                $credentials['endpoint']
            ),
            'fcm' => new FCM($credentials['serverKey']),
            default => null
        };
    }

    public function email($record): ?EmailAdapter
    {
        $credentials = $record->getAttribute('credentials');

        return match ($record->getAttribute('provider')) {
            'mailgun' => new Mailgun($credentials['apiKey'], $credentials['domain']),
            'sendgrid' => new SendGrid($credentials['apiKey']),
            default => null
        };
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $providerId = $this->args['providerId'];
        $providerRecord =
        $this
        ->getConsoleDB()
        ->getDocument('providers', $providerId);

        $provider = match ($providerRecord->getAttribute('type')) {//stubbbbbbed.
            'sms' => $this->sms($providerRecord),
            'push' => $this->push($providerRecord),
            'email' => $this->email($providerRecord),
            default => null
        };

        // Query for the provider
        // switch on provider name
        // call function passing needed credentials returns required provider.

        $messageId = $this->args['messageId'];
        $messageRecord =
          $this
          ->getConsoleDB()
          ->getDocument('messages', $messageId);

        $message = match ($providerRecord->getAttribute('type')) {
            'sms' => $this->buildSMSMessage($messageRecord->getArrayCopy()),
            'push' => $this->buildPushMessage($messageRecord->getArrayCopy()),
            'email' => $this->buildEmailMessage($messageRecord->getArrayCopy()),
            default => null
        };

        $provider->send($message);
    }

    public function shutdown(): void
    {
    }

    private function buildEmailMessage($data): array
    {
        $from = $data['from'];
        $to = $data['to'];
        $subject = $data['subject'];
        $body = $data['content'];

        return [
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    private function buildSMSMessage($data): array
    {
        $from = $data['from'];
        $to = $data['to'];
        $body = $data['content'];

        return [
            'from' => $from,
            'to' => $to,
            'body' => $body,
        ];
    }

    private function buildPushMessage($data): array
    {
        $to = $data['to'];
        $title = $data['title'];
        $body = $data['body'];
        $data = $data['data'];

        return [
            'to' => $to,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];
    }
}
