<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Helpers\ID;
use Utopia\DSN\DSN;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Adapter\Push\APNS;
use Utopia\Messaging\Adapter\Push\FCM;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\Mock;
use Utopia\Messaging\Adapter\SMS\Msg91;
use Utopia\Messaging\Adapter\SMS\Telesign;
use Utopia\Messaging\Adapter\SMS\Textmagic;
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Adapter\SMS\Vonage;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Response;

use function Swoole\Coroutine\batch;

class Messaging extends Action
{
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

        if (!\is_null($payload['message']) && !\is_null($payload['recipients'])) {
            if ($payload['providerType'] === MESSAGE_TYPE_SMS) {
                $this->processInternalSMSMessage(new Document($payload['message']), $payload['recipients']);
            }
        } else {
            $message = $dbForProject->getDocument('messages', $payload['messageId']);

            $this->processMessage($dbForProject, $message);
        }
    }

    private function processMessage(Database $dbForProject, Document $message): void
    {
        $topicsId = $message->getAttribute('topics', []);
        $targetsId = $message->getAttribute('targets', []);
        $usersId = $message->getAttribute('users', []);

        /**
        * @var Document[] $recipients
        */
        $recipients = [];

        if (\count($topicsId) > 0) {
            $topics = $dbForProject->find('topics', [Query::equal('$id', $topicsId)]);
            foreach ($topics as $topic) {
                $targets = \array_filter($topic->getAttribute('targets'), fn (Document $target) => $target->getAttribute('providerType') === $message->getAttribute('providerType'));
                $recipients = \array_merge($recipients, $targets);
            }
        }

        if (\count($usersId) > 0) {
            $users = $dbForProject->find('users', [Query::equal('$id', $usersId)]);
            foreach ($users as $user) {
                $targets = \array_filter($user->getAttribute('targets'), fn (Document $target) => $target->getAttribute('providerType') === $message->getAttribute('providerType'));
                $recipients = \array_merge($recipients, $targets);
            }
        }

        if (\count($targetsId) > 0) {
            $targets = $dbForProject->find('targets', [Query::equal('$id', $targetsId)]);
            $recipients = \array_merge($recipients, $targets);
        }

        $primaryProvider = $dbForProject->findOne('providers', [
            Query::equal('enabled', [true]),
            Query::equal('type', [$recipients[0]->getAttribute('providerType')]),
        ]);

        /**
        * @var array<string, array<string>> $identifiersByProviderId
        */
        $identifiersByProviderId = [];

        /**
        * @var Document[] $providers
        */
        $providers = [];
        foreach ($recipients as $recipient) {
            $providerId = $recipient->getAttribute('providerId');

            if (!$providerId && $primaryProvider instanceof Document && !$primaryProvider->isEmpty()) {
                $providerId = $primaryProvider->getId();
            }

            if ($providerId) {
                if (!isset($identifiersByProviderId[$providerId])) {
                    $identifiersByProviderId[$providerId] = [];
                }
                $identifiersByProviderId[$providerId][] = $recipient->getAttribute('identifier');
            }
        }

        /**
        * @var array[] $results
        */
        $results = batch(\array_map(function ($providerId) use ($identifiersByProviderId, $providers, $primaryProvider, $message, $dbForProject) {
            return function () use ($providerId, $identifiersByProviderId, $providers, $primaryProvider, $message, $dbForProject) {
                $provider = new Document();

                if ($primaryProvider->getId() === $providerId) {
                    $provider = $primaryProvider;
                } else {
                    $provider = $dbForProject->getDocument('providers', $providerId, [Query::equal('enabled', [true])]);

                    if ($provider->isEmpty()) {
                        $provider = $primaryProvider;
                    }
                }

                $providers[] = $provider;
                $identifiers = $identifiersByProviderId[$providerId];

                $adapter = match ($provider->getAttribute('type')) {
                    MESSAGE_TYPE_SMS => $this->sms($provider),
                    MESSAGE_TYPE_PUSH => $this->push($provider),
                    MESSAGE_TYPE_EMAIL  => $this->email($provider),
                    default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
                };

                $maxBatchSize = $adapter->getMaxMessagesPerRequest();
                $batches = \array_chunk($identifiers, $maxBatchSize);
                $batchIndex = 0;

                $results = batch(\array_map(function ($batch) use ($message, $provider, $adapter, $batchIndex, $dbForProject) {
                    return function () use ($batch, $message, $provider, $adapter, $batchIndex, $dbForProject) {
                        $deliveredTotal = 0;
                        $deliveryErrors = [];
                        $messageData = clone $message;
                        $messageData->setAttribute('to', $batch);

                        $data = match ($provider->getAttribute('type')) {
                            MESSAGE_TYPE_SMS => $this->buildSMSMessage($messageData, $provider),
                            MESSAGE_TYPE_PUSH => $this->buildPushMessage($messageData),
                            MESSAGE_TYPE_EMAIL => $this->buildEmailMessage($messageData, $provider),
                            default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
                        };

                        try {
                            $response = new Response($provider->getAttribute('type'));
                            $response->fromArray(\json_decode($adapter->send($data)));

                            $deliveredTotal += $response->getDeliveredTo();
                            $details[] = $response->getDetails();
                            foreach ($details as $detail) {
                                if ($detail['status'] === 'failure') {
                                    $deliveryErrors[] = `Failed sending to target {$detail['recipient']} with error: {$detail['error']}`;
                                }

                                // Deleting push targets when token has expired.
                                if ($detail['error'] === 'Expired token.') {
                                    $target = $dbForProject->findOne('targets', [Query::equal('identifier', [$detail['recipient']])]);
                                    if ($target instanceof Document && !$target->isEmpty()) {
                                        $dbForProject->deleteDocument('targets', $target->getId());
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $deliveryErrors[] = 'Failed sending to targets ' . $batchIndex + 1 . '-' . \count($batch) . ' with error: ' . $e->getMessage();
                        } finally {
                            $batchIndex++;
                            return [
                                'deliveredTotal' => $deliveredTotal,
                                'deliveryErrors' => $deliveryErrors,
                            ];
                        }
                    };
                }, $batches));

                return $results;
            };
        }, \array_keys($identifiersByProviderId)));

        $results = array_merge(...$results);

        $deliveredTotal = 0;
        $deliveryErrors = [];

        foreach ($results as $result) {
            $deliveredTotal += $result['deliveredTotal'];
            $deliveryErrors = \array_merge($deliveryErrors, $result['deliveryErrors']);
        }

        $message->setAttribute('deliveryErrors', $deliveryErrors);

        if (\count($message->getAttribute('deliveryErrors')) > 0) {
            $message->setAttribute('status', 'failed');
        } else {
            $message->setAttribute('status', 'sent');
        }

        $message->removeAttribute('to');

        foreach ($providers as $provider) {
            $message->setAttribute('search', "{$message->getAttribute('search')} {$provider->getAttribute('name')} {$provider->getAttribute('provider')} {$provider->getAttribute('type')}");
        }

        $message->setAttribute('deliveredTotal', $deliveredTotal);
        $message->setAttribute('deliveredAt', DateTime::now());

        $dbForProject->updateDocument('messages', $message->getId(), $message);
    }

    private function processInternalSMSMessage(Document $message, array $recipients): void
    {
        if (empty(App::getEnv('_APP_SMS_PROVIDER')) || empty(App::getEnv('_APP_SMS_FROM'))) {
            Console::info('Skipped SMS processing. No Phone configuration has been set.');
            return;
        }

        $smsDSN = new DSN(App::getEnv('_APP_SMS_PROVIDER'));
        $host = $smsDSN->getHost();
        $password = $smsDSN->getPassword();
        $user = $smsDSN->getUser();

        $from = App::getEnv('_APP_SMS_FROM');

        $provider = new Document([
            '$id' => ID::unique(),
            'provider' => $host,
            'type' => MESSAGE_TYPE_SMS,
            'name' => 'Internal SMS',
            'enabled' => true,
            'credentials' => match ($host) {
                'twilio' => [
                    'accountSid' => $user,
                    'authToken' => $password
                ],
                'textmagic' => [
                    'username' => $user,
                    'apiKey' => $password
                ],
                'telesign' => [
                    'username' => $user,
                    'password' => $password
                ],
                'msg91' => [
                    'senderId' => $user,
                    'authKey' => $password
                ],
                'vonage' => [
                    'apiKey' => $user,
                    'apiSecret' => $password
                ],
                default => null
            },
            'options' => [
                'from' => $from
            ]
        ]);

        $adapter = $this->sms($provider);

        $maxBatchSize = $adapter->getMaxMessagesPerRequest();
        $batches = \array_chunk($recipients, $maxBatchSize);
        $batchIndex = 0;

        batch(\array_map(function ($batch) use ($message, $provider, $adapter, $batchIndex) {
            return function () use ($batch, $message, $provider, $adapter, $batchIndex) {
                $message->setAttribute('to', $batch);

                $data = $this->buildSMSMessage($message, $provider);

                try {
                    $adapter->send($data);
                } catch (\Exception $e) {
                    Console::error('Failed sending to targets ' . $batchIndex + 1 . '-' . \count($batch) . ' with error: ' . $e->getMessage());
                }
            };
        }, $batches));
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
            'textmagic' => new Textmagic($credentials['username'], $credentials['apiKey']),
            'telesign' => new Telesign($credentials['username'], $credentials['password']),
            'msg91' => new Msg91($credentials['senderId'], $credentials['authKey'], $credentials['templateId']),
            'vonage' => new Vonage($credentials['apiKey'], $credentials['apiSecret']),
            default => null
        };
    }

    private function push(Document $provider): ?PushAdapter
    {
        $credentials = $provider->getAttribute('credentials');
        return match ($provider->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
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
            'mock' => new Mock('username', 'password'),
            'mailgun' => new Mailgun($credentials['apiKey'], $credentials['domain'], $credentials['isEuRegion']),
            'sendgrid' => new Sendgrid($credentials['apiKey']),
            default => null
        };
    }

    private function buildEmailMessage(Document $message, Document $provider): Email
    {
        $fromName = $provider['options']['fromName'];
        $fromEmail = $provider['options']['fromEmail'];
        $replyToEmail = null;
        $replyToName = null;
        $cc = null;
        $bcc = null;

        if (isset($provider['options']['replyToName']) && isset($provider['options']['replyToEmail'])) {
            $replyToName = $provider['options']['replyToName'];
            $replyToEmail = $provider['options']['replyToEmail'];
        }

        if (isset($provider['options']['cc'])) {
            foreach ($provider['options']['cc'] as $ccEmail) {
                if (is_array($cc)) {
                    $cc[] = [
                        'email' => $ccEmail,
                    ];
                } else {
                    $cc = [
                        [
                            'email' => $ccEmail,
                        ]
                    ];
                }
            }
        }

        if (isset($provider['options']['bcc'])) {
            foreach ($provider['options']['bcc'] as $bccEmail) {
                if (is_array($bcc)) {
                    $bcc[] = [
                        'email' => $bccEmail,
                    ];
                } else {
                    $bcc = [
                        [
                            'email' => $bccEmail,
                        ]
                    ];
                }
            }
        }

        $to = $message['to'];
        $subject = $message['data']['subject'];
        $content = $message['data']['content'];
        $html = $message['data']['html'];

        return new Email($to, $subject, $content, $fromName, $fromEmail, $replyToName, $replyToEmail, $cc, $bcc, html: $html);
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
