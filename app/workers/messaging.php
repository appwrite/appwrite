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
use Appwrite\Extend\Exception;

use function Swoole\Coroutine\batch;

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
        return "messaging";
    }


    public function init(): void
    {
    }

    public function run(): void
    {
        $project = new Document($this->args['project']);
        $this->dbForProject = $this->getProjectDB($project);

        $message = $this->dbForProject->getDocument('messages', $this->args['messageId']);

        $provider = $this->dbForProject->getDocument('providers', $message->getAttribute('providerId'));

        $this->processMessage($message, $provider);
    }

    private function processMessage(Document $messageRecord, Document $providerRecord): void
    {
        $provider = match ($providerRecord->getAttribute('type')) {
            'sms' => $this->sms($providerRecord),
            'push' => $this->push($providerRecord),
            'email' => $this->email($providerRecord),
            default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
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
        $recipients = \array_filter($recipients, fn (Document $recipient) => $recipient->getAttribute('providerId') === $providerRecord->getId());

        $identifiers = \array_map(function (Document $recipient) {
            return $recipient->getAttribute('identifier');
        }, $recipients);

        $maxBatchSize = $provider->getMaxMessagesPerRequest();
        $batches = \array_chunk($identifiers, $maxBatchSize);

        $results = batch(\array_map(function ($batch) use ($messageRecord, $providerRecord, $provider) {
            return function () use ($batch, $messageRecord, $providerRecord, $provider) {
                $deliveredTo = 0;
                $deliveryErrors = [];
                $messageData = clone $messageRecord;
                $messageData->setAttribute('to', $batch);
                $message = match ($providerRecord->getAttribute('type')) {
                    'sms' => $this->buildSMSMessage($messageData, $providerRecord),
                    'push' => $this->buildPushMessage($messageData),
                    'email' => $this->buildEmailMessage($messageData, $providerRecord),
                    default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
                };
                try {
                    $provider->send($message);
                    $deliveredTo += \count($batch);
                } catch (\Exception $e) {
                    foreach ($batch as $identifier) {
                        $deliveryErrors[] = 'Failed to send message to target' . $identifier . ': ' . $e->getMessage();
                    }
                } finally {
                    return [
                        'deliveredTo' => $deliveredTo,
                        'deliveryErrors' => $deliveryErrors,
                    ];
                }
            };
        }, $batches));

        $deliveredTo = 0;
        $deliveryErrors = [];
        foreach ($results as $result) {
            $deliveredTo += $result['deliveredTo'];
            $deliveryErrors = \array_merge($deliveryErrors, $result['deliveryErrors']);
        }
        $messageRecord->setAttribute('deliveryErrors', $deliveryErrors);

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

    private function sms(Document $document): ?SMSAdapter
    {
        $credentials = $document->getAttribute('credentials');
        return match ($document->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
            'twilio' => new Twilio($credentials['accountSid'], $credentials['authToken']),
            'text-magic' => new TextMagic($credentials['username'], $credentials['apiKey']),
            'telesign' => new Telesign($credentials['username'], $credentials['password']),
            'msg91' => new Msg91($credentials['senderId'], $credentials['authKey']),
            'vonage' => new Vonage($credentials['apiKey'], $credentials['apiSecret']),
            default => null
        };
    }

    private function push(Document $document): ?PushAdapter
    {
        $credentials = $document->getAttribute('credentials');
        return match ($document->getAttribute('provider')) {
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

    private function email(Document $document): ?EmailAdapter
    {
        $credentials = $document->getAttribute('credentials');
        return match ($document->getAttribute('provider')) {
            'mailgun' => new Mailgun($credentials['apiKey'], $credentials['domain'], $credentials['isEuRegion']),
            'sendgrid' => new SendGrid($credentials['apiKey']),
            default => null
        };
    }

    private function buildEmailMessage(Document $message, Document $provider): Email
    {
        $from = $provider['options']['from'];
        $to = $message['to'];
        $subject = $message['data']['subject'];
        $content = $message['data']['content'];
        $html = $message['data']['html'];

        return new Email($to, $subject, $content, $from, null, $html);
    }

    private function buildSMSMessage(Document $message, Document $provider): SMS
    {
        $to = $message['to'];
        $content = $message['data']['content'];
        $from = $provider['options']['from'];

        return new SMS($to, $content, $from);
    }

    private function buildPushMessage(Document $message): Push
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
