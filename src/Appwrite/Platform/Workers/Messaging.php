<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
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

use function Swoole\Coroutine\batch;

require_once __DIR__ . '/../init.php';

class Messaging extends Action
{
    protected ?SMSAdapter $sms = null;
    protected ?PushAdapter $push = null;
    protected ?EmailAdapter $email = null;

    protected ?Database $dbForProject = null;


    public static function getName(): string
    {
        return "messaging";
    }


    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Messaging worker')
            ->inject('message')
            ->inject('dbForProject')
            ->callback(fn(Message $message, Database $dbForProject) => $this->action($message, $dbForProject));
    }

    /**
     * @param Message $message
     * @param Database $dbForProject
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Database $dbForProject): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            Console::error('Payload arg not found');
            return;
        }


        $message = $dbForProject->getDocument('messages', $payload['messageId']);

        $provider = $dbForProject->getDocument('providers', $message->getAttribute('providerId'));

        $this->processMessage($message, $provider);
    }

    private function processMessage(Document $message, Document $provider): void
    {
        $adapter = match ($provider->getAttribute('type')) {
            'sms' => $this->sms($provider),
            'push' => $this->push($provider),
            'email' => $this->email($provider),
            default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
        };

        $recipientsId = $message->getAttribute('to');

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
        $recipients = \array_filter($recipients, function (Document $recipient) use ($provider) {
                return $recipient->getAttribute('providerId') === $provider->getId();
        });

        $identifiers = \array_map(function (Document $recipient) {
            return $recipient->getAttribute('identifier');
        }, $recipients);

        $maxBatchSize = $adapter->getMaxMessagesPerRequest();
        $batches = \array_chunk($identifiers, $maxBatchSize);
        $batchIndex = 0;

        $results = batch(\array_map(function ($batch) use ($message, $provider, $adapter, $batchIndex) {
            return function () use ($batch, $message, $provider, $adapter, $batchIndex) {
                $deliveredTo = 0;
                $deliveryErrors = [];
                $messageData = clone $message;
                $messageData->setAttribute('to', $batch);
                $data = match ($provider->getAttribute('type')) {
                    'sms' => $this->buildSMSMessage($messageData, $provider),
                    'push' => $this->buildPushMessage($messageData),
                    'email' => $this->buildEmailMessage($messageData, $provider),
                    default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
                };
                try {
                    $adapter->send($data);
                    $deliveredTo += \count($batch);
                } catch (\Exception $e) {
                    $deliveryErrors[] = 'Failed sending to targets ' . $batchIndex + 1 . '-' . \count($batch) . ' with error: ' . $e->getMessage();
                } finally {
                    $batchIndex++;
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
        $message->setAttribute('deliveryErrors', $deliveryErrors);

        if (\count($message->getAttribute('deliveryErrors')) > 0) {
            $message->setAttribute('status', 'failed');
        } else {
            $message->setAttribute('status', 'sent');
        }
        $message->setAttribute('to', $recipientsId);
        $message->setAttribute('deliveredTo', $deliveredTo);
        $message->setAttribute('deliveredAt', DateTime::now());

        $this->dbForProject->updateDocument('messages', $message->getId(), $message);
    }

    public function shutdown(): void
    {
    }

    private function sms(Document $provider): ?SMSAdapter
    {
        $credentials = $provider->getAttribute('credentials');
        return match ($provider->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
            'twilio' => new Twilio($credentials['accountSid'], $credentials['authToken']),
            'text-magic' => new TextMagic($credentials['username'], $credentials['apiKey']),
            'telesign' => new Telesign($credentials['username'], $credentials['password']),
            'msg91' => new Msg91($credentials['senderId'], $credentials['authKey']),
            'vonage' => new Vonage($credentials['apiKey'], $credentials['apiSecret']),
            default => null
        };
    }

    private function push(Document $provider): ?PushAdapter
    {
        $credentials = $provider->getAttribute('credentials');
        return match ($provider->getAttribute('provider')) {
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

    private function email(Document $provider): ?EmailAdapter
    {
        $credentials = $provider->getAttribute('credentials');
        return match ($provider->getAttribute('provider')) {
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
