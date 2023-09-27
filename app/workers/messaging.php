<?php

use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Messaging\Adapters\SMS as SMSAdapter;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Adapters\Push as PushAdapter;
use Utopia\Messaging\Adapters\Push\APNS;
use Utopia\Messaging\Adapters\Push\FCM;
use Utopia\Messaging\Adapters\Email as EmailAdapter;
use Utopia\Messaging\Adapters\Email\Mailgun;
use Utopia\Messaging\Adapters\Email\SendGrid;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Messages\SMS;

require_once __DIR__ . '/../init.php';

Console::title('Messaging V1 Worker');
Console::success(APP_NAME . ' messaging worker v1 has started' . "\n");

class MessagingV1 extends Worker
{
    protected ?SMSAdapter $sms = null;
    protected ?PushAdapter $push = null;
    protected ?EmailAdapter $email = null;

    protected ?Database $dbForProject = null;


    public function getName(): string
    {
        return "mails";
    }

    public function sms($record): ?SMSAdapter
    {
        $credentials = $record->getAttribute('credentials');
        return match ($record->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
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
            'mailgun' => new Mailgun($credentials['apiKey'], $credentials['domain'], $credentials['isEuRegion']),
            'sendgrid' => new SendGrid($credentials['apiKey']),
            default => null
        };
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $project = new Document($this->args['project']);
        $this->dbForProject = $this->getProjectDB($project);

        $messageRecord = $this->dbForProject->getDocument('messages', $this->args['messageId']);

        $providerId = $messageRecord->getAttribute('providerId');
        $providerRecord = $this->dbForProject->getDocument('providers', $providerId);

        $this->processMessage($messageRecord, $providerRecord);
    }

    private function processMessage(Document $messageRecord, Document $providerRecord): void
    {
        $provider = match ($providerRecord->getAttribute('type')) {
            'sms' => $this->sms($providerRecord),
            'push' => $this->push($providerRecord),
            'email' => $this->email($providerRecord),
            default => null
        };

        $recipientsId = $messageRecord->getAttribute('to');

        /**
        * @var Document[] $recipients
        */
        $recipients = [];


        $topics = $this->dbForProject->find('topics', [Query::equal('$id', $recipientsId)]);
        foreach ($topics as $topic) {
            $recipients = \array_merge($recipients, $topic->getAttribute('targets'));
        }

        $users = $this->dbForProject->find('users', [Query::equal('$id', $recipientsId)]);
        foreach ($users as $user) {
            $recipients = \array_merge($recipients, $user->getAttribute('targets'));
        }

        $targets = $this->dbForProject->find('targets', [Query::equal('$id', $recipientsId)]);
        $recipients = \array_merge($recipients, $targets);

        $identifiers = \array_map(function (Document $recipient) {
            return $recipient->getAttribute('identifier');
        }, $recipients);

        $maxBatchSize = $provider->getMaxMessagesPerRequest();
        $batches = \array_chunk($identifiers, $maxBatchSize);
        $deliveredTo = 0;

        foreach ($batches as $batch) {
            $messageRecord->setAttribute('to', $batch);
            $message = match ($providerRecord->getAttribute('type')) {
                'sms' => $this->buildSMSMessage($messageRecord, $providerRecord),
                'push' => $this->buildPushMessage($messageRecord),
                'email' => $this->buildEmailMessage($messageRecord, $providerRecord),
                default => null
            };
            try {
                $provider->send($message);
                $deliveredTo += \count($batch);
            } catch (Exception $e) {
                $deliveryErrors = $messageRecord->getAttribute('deliveryErrors');
                foreach ($batch as $identifier) {
                    $deliveryErrors[] = 'Failed to send message to target' . $identifier . ': ' . $e->getMessage();
                }
                $messageRecord->setAttribute('deliveryErrors', $deliveryErrors);
            }
        }

        if (\count($messageRecord->getAttribute('deliveryErrors')) > 0) {
            $messageRecord->setAttribute('status', 'failed');
        } else {
            $messageRecord->setAttribute('status', 'sent');
        }
        $messageRecord->setAttribute('to', $recipientsId);
        $messageRecord->setAttribute('deliveredTo', $deliveredTo);
        $messageRecord->setAttribute('deliveryTime', DateTime::now());

        $this->dbForProject->updateDocument('messages', $messageRecord->getId(), $messageRecord);
    }

    public function shutdown(): void
    {
    }

    private function buildEmailMessage($message, $provider): Email
    {
        $from = $provider['options']['from'];
        $to = $message['to'];
        $subject = $message['data']['subject'];
        $content = $message['data']['content'];
        $html = $message['data']['html'];
        return  new Email(to: $to, subject: $subject, content: $content, from: $from, html: $html);
    }

    private function buildSMSMessage($message, $provider): SMS
    {
        $to = $message['to'];
        $content = $message['data']['content'];
        $from = $provider['options']['from'];

        return new SMS($to, $content, $from);
    }

    private function buildPushMessage($message): Push
    {
        $to = $message['to'];
        $title = $message['data']['title'];
        $body = $message['data']['body'];
        $data = $message['data']['data'];
        $action = $message['data']['action'];
        $sound = $message['data']['sound'];
        $icon = $message['data']['icon'];
        $color = $message['data']['color'];
        $tag = $message['data']['tag'];
        $badge = $message['data']['badge'];
        return new Push($to, $title, $body, $data, $action, $sound, $icon, $color, $tag, $badge);
    }
}
