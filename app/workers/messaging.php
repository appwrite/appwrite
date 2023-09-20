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
        $project = new Document($this->args['project']);
        $this->dbForProject = $this->getProjectDB($project);

        $messageRecord = new Document($this->args['message']);

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
        \array_merge($recipients, $targets);

        $identifiers = \array_map(function (Document $recipient) {
            return $recipient->getAttribute('identifier');
        }, $recipients);

        $maxBatchSize = $provider->getMaxMessagesPerRequest();
        $batches = \array_chunk($identifiers, $maxBatchSize);
        $message = $messageRecord->getArrayCopy();
        $deliveredTo = 0;

        foreach ($batches as $batch) {
            $message['to'] = $batch;
            $message = match ($providerRecord->getAttribute('type')) {
                'sms' => $this->buildSMSMessage($message),
                'push' => $this->buildPushMessage($message),
                'email' => $this->buildEmailMessage($message),
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

        $messageRecord->setAttribute('deliveredTo', $deliveredTo);
        $messageRecord->setAttribute('deliveryTime', DateTime::now());

        $this->dbForProject->updateDocument('messages', $messageRecord->getId(), $messageRecord);
    }

    public function shutdown(): void
    {
    }

    private function buildEmailMessage($data): Email
    {
        $from = $data['data']['from'];
        $to = $data['to'];
        $subject = $data['data']['subject'];
        $content = $data['data']['content'];
        $html = $data['data']['html'];
        return  new Email(to: $to, subject: $subject, content: $content, from: $from, html: $html);
    }

    private function buildSMSMessage($data): SMS
    {
        $to = $data['to'];
        $content = $data['data']['content'];
        $from = $data['data']['from'];

        return new SMS($to, $content, $from);
    }

    private function buildPushMessage($data): Push
    {
        $to = $data['to'];
        $title = $data['data']['title'];
        $body = $data['data']['body'];
        $data = $data['data']['data'];
        $action = $data['data']['action'];
        $sound = $data['data']['sound'];
        $icon = $data['data']['icon'];
        $color = $data['data']['color'];
        $tag = $data['data']['tag'];
        $badge = $data['data']['badge'];
        return new Push($to, $title, $body, $data, $action, $sound, $icon, $color, $tag, $badge);
    }
}
