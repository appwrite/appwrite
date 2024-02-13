<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Usage;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Status as MessageStatus;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\DSN\DSN;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Adapter\Email\SMTP;
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
use Utopia\Platform\Action;
use Utopia\Queue\Message;

use function Swoole\Coroutine\batch;

class Messaging extends Action
{
    public static function getName(): string
    {
        return 'messaging';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Messaging worker')
            ->inject('message')
            ->inject('log')
            ->inject('dbForProject')
            ->inject('queueForUsage')
            ->callback(fn(Message $message, Log $log, Database $dbForProject, Usage $queueForUsage) => $this->action($message, $log, $dbForProject, $queueForUsage));
    }

    /**
     * @param Message $message
     * @param Log $log
     * @param Database $dbForProject
     * @param Usage $queueForUsage
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Log $log, Database $dbForProject, Usage $queueForUsage): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }


        if (
            !\is_null($payload['message'])
            && !\is_null($payload['recipients'])
            && $payload['providerType'] === MESSAGE_TYPE_SMS
        ) {
            // Message was triggered internally
            $this->processInternalSMSMessage(
                new Document($payload['message']),
                new Document($payload['project'] ?? []),
                $payload['recipients'],
                $queueForUsage,
                $log,
            );
        } else {
            $message = $dbForProject->getDocument('messages', $payload['messageId']);

            $this->processMessage($dbForProject, $message);
        }
    }

    private function processMessage(Database $dbForProject, Document $message): void
    {
        $topicIds = $message->getAttribute('topics', []);
        $targetIds = $message->getAttribute('targets', []);
        $userIds = $message->getAttribute('users', []);

        /**
         * @var array<Document> $recipients
         */
        $recipients = [];

        if (\count($topicIds) > 0) {
            $topics = $dbForProject->find('topics', [
                Query::equal('$id', $topicIds),
                Query::limit(\count($topicIds)),
            ]);
            foreach ($topics as $topic) {
                $targets = \array_filter($topic->getAttribute('targets'), fn(Document $target) =>
                    $target->getAttribute('providerType') === $message->getAttribute('providerType'));
                $recipients = \array_merge($recipients, $targets);
            }
        }

        if (\count($userIds) > 0) {
            $users = $dbForProject->find('users', [
                Query::equal('$id', $userIds),
                Query::limit(\count($userIds)),
            ]);
            foreach ($users as $user) {
                $targets = \array_filter($user->getAttribute('targets'), fn(Document $target) =>
                    $target->getAttribute('providerType') === $message->getAttribute('providerType'));
                $recipients = \array_merge($recipients, $targets);
            }
        }

        if (\count($targetIds) > 0) {
            $targets = $dbForProject->find('targets', [
                Query::equal('$id', $targetIds),
                Query::limit(\count($targetIds)),
            ]);
            $targets = \array_filter($targets, fn(Document $target) =>
                $target->getAttribute('providerType') === $message->getAttribute('providerType'));
            $recipients = \array_merge($recipients, $targets);
        }

        if (empty($recipients)) {
            $dbForProject->updateDocument('messages', $message->getId(), $message->setAttributes([
                'status' => MessageStatus::FAILED,
                'deliveryErrors' => ['No valid recipients found.']
            ]));

            Console::warning('No valid recipients found.');
            return;
        }

        $fallback = $dbForProject->findOne('providers', [
            Query::equal('enabled', [true]),
            Query::equal('type', [$recipients[0]->getAttribute('providerType')]),
        ]);

        if ($fallback === false || $fallback->isEmpty()) {
            $dbForProject->updateDocument('messages', $message->getId(), $message->setAttributes([
                'status' => MessageStatus::FAILED,
                'deliveryErrors' => ['No fallback provider found.']
            ]));

            Console::warning('No fallback provider found.');
            return;
        }

        /**
         * @var array<string, array<string>> $identifiers
         */
        $identifiers = [];

        /**
         * @var Document[] $providers
         */
        $providers = [
            $fallback->getId() => $fallback
        ];

        foreach ($recipients as $recipient) {
            $providerId = $recipient->getAttribute('providerId');

            if (
                !$providerId
                && $fallback instanceof Document
                && !$fallback->isEmpty()
                && $fallback->getAttribute('enabled')
            ) {
                $providerId = $fallback->getId();
            }

            if ($providerId) {
                if (!\array_key_exists($providerId, $identifiers)) {
                    $identifiers[$providerId] = [];
                }
                $identifiers[$providerId][] = $recipient->getAttribute('identifier');
            }
        }

        /**
         * @var array<array> $results
         */
        $results = batch(\array_map(function ($providerId) use ($identifiers, $providers, $fallback, $message, $dbForProject) {
            return function () use ($providerId, $identifiers, $providers, $fallback, $message, $dbForProject) {
                if (\array_key_exists($providerId, $providers)) {
                    $provider = $providers[$providerId];
                } else {
                    $provider = $dbForProject->getDocument('providers', $providerId);

                    if ($provider->isEmpty() || !$provider->getAttribute('enabled')) {
                        $provider = $fallback;
                    } else {
                        $providers[$providerId] = $provider;
                    }
                }

                $identifiers = $identifiers[$providerId];

                $adapter = match ($provider->getAttribute('type')) {
                    MESSAGE_TYPE_SMS => $this->sms($provider),
                    MESSAGE_TYPE_PUSH => $this->push($provider),
                    MESSAGE_TYPE_EMAIL => $this->email($provider),
                    default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
                };

                $maxBatchSize = $adapter->getMaxMessagesPerRequest();
                $batches = \array_chunk($identifiers, $maxBatchSize);
                $batchIndex = 0;

                return batch(\array_map(function ($batch) use ($message, $provider, $adapter, &$batchIndex, $dbForProject) {
                    return function () use ($batch, $message, $provider, $adapter, &$batchIndex, $dbForProject) {
                        $deliveredTotal = 0;
                        $deliveryErrors = [];
                        $messageData = clone $message;
                        $messageData->setAttribute('to', $batch);

                        $data = match ($provider->getAttribute('type')) {
                            MESSAGE_TYPE_SMS => $this->buildSMSMessage($messageData, $provider),
                            MESSAGE_TYPE_PUSH => $this->buildPushMessage($messageData),
                            MESSAGE_TYPE_EMAIL => $this->buildEmailMessage($dbForProject, $messageData, $provider),
                            default => throw new Exception(Exception::PROVIDER_INCORRECT_TYPE)
                        };

                        try {
                            $response = $adapter->send($data);
                            $deliveredTotal += $response['deliveredTo'];
                            foreach ($response['results'] as $result) {
                                if ($result['status'] === 'failure') {
                                    $deliveryErrors[] = "Failed sending to target {$result['recipient']} with error: {$result['error']}";
                                }

                                // Deleting push targets when token has expired.
                                if (($result['error'] ??  '') === 'Expired device token.') {
                                    $target = $dbForProject->findOne('targets', [
                                        Query::equal('identifier', [$result['recipient']])
                                    ]);

                                    if ($target instanceof Document && !$target->isEmpty()) {
                                        $dbForProject->updateDocument(
                                            'targets',
                                            $target->getId(),
                                            $target->setAttribute('expired', true)
                                        );
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
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
            };
        }, \array_keys($identifiers)));

        $results = array_merge(...$results);

        $deliveredTotal = 0;
        $deliveryErrors = [];

        foreach ($results as $result) {
            $deliveredTotal += $result['deliveredTotal'];
            $deliveryErrors = \array_merge($deliveryErrors, $result['deliveryErrors']);
        }

        $message->setAttribute('deliveryErrors', $deliveryErrors);

        if (\count($message->getAttribute('deliveryErrors')) > 0) {
            $message->setAttribute('status', MessageStatus::FAILED);
        } else {
            $message->setAttribute('status', MessageStatus::SENT);
        }

        $message->removeAttribute('to');

        foreach ($providers as $provider) {
            $message->setAttribute('search', "{$message->getAttribute('search')} {$provider->getAttribute('name')} {$provider->getAttribute('provider')} {$provider->getAttribute('type')}");
        }

        $message->setAttribute('deliveredTotal', $deliveredTotal);
        $message->setAttribute('deliveredAt', DateTime::now());

        $dbForProject->updateDocument('messages', $message->getId(), $message);
    }

    private function processInternalSMSMessage(Document $message, Document $project, array $recipients, Usage $queueForUsage, Log $log): void
    {
        if (empty(App::getEnv('_APP_SMS_PROVIDER')) || empty(App::getEnv('_APP_SMS_FROM'))) {
            throw new \Exception('Skipped SMS processing. Missing "_APP_SMS_PROVIDER" or "_APP_SMS_FROM" environment variables.');
        }

        if ($project->isEmpty()) {
            throw new Exception('Project not set in payload');
        }

        Console::log('Project: ' . $project->getId());

        $denyList = App::getEnv('_APP_SMS_PROJECTS_DENY_LIST', '');
        $denyList = explode(',', $denyList);

        if (\in_array($project->getId(), $denyList)) {
            Console::error('Project is in the deny list. Skipping...');
            return;
        }

        $smsDSN = new DSN(App::getEnv('_APP_SMS_PROVIDER'));
        $host = $smsDSN->getHost();
        $password = $smsDSN->getPassword();
        $user = $smsDSN->getUser();

        $log->addTag('type', $host);

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
                    'customerId' => $user,
                    'apiKey' => $password
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

        batch(\array_map(function ($batch) use ($message, $provider, $adapter, $batchIndex, $project, $queueForUsage) {
            return function () use ($batch, $message, $provider, $adapter, $batchIndex, $project, $queueForUsage) {
                $message->setAttribute('to', $batch);

                $data = $this->buildSMSMessage($message, $provider);

                try {
                    $adapter->send($data);

                    $queueForUsage
                        ->setProject($project)
                        ->addMetric(METRIC_MESSAGES, 1)
                        ->trigger();
                } catch (\Throwable $e) {
                    throw new Exception('Failed sending to targets ' . $batchIndex + 1 . '-' . \count($batch) . ' with error: ' . $e->getMessage(), 500);
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
            'telesign' => new Telesign($credentials['customerId'], $credentials['apiKey']),
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
            ),
            'fcm' => new FCM(\json_encode($credentials['serviceAccountJSON'])),
            default => null
        };
    }

    private function email(Document $provider): ?EmailAdapter
    {
        $credentials = $provider->getAttribute('credentials', []);
        $options = $provider->getAttribute('options', []);

        return match ($provider->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
            'smtp' => new SMTP(
                $credentials['host'],
                $credentials['port'],
                $credentials['username'],
                $credentials['password'],
                $options['encryption'],
                $options['autoTLS'],
                $options['mailer'],
            ),
            'mailgun' => new Mailgun(
                $credentials['apiKey'],
                $credentials['domain'],
                $credentials['isEuRegion']
            ),
            'sendgrid' => new Sendgrid($credentials['apiKey']),
            default => null
        };
    }

    private function buildEmailMessage(Database $dbForProject, Document $message, Document $provider): Email
    {
        $fromName = $provider['options']['fromName'] ?? null;
        $fromEmail = $provider['options']['fromEmail'] ?? null;
        $replyToEmail = $provider['options']['replyToEmail'] ?? null;
        $replyToName = $provider['options']['replyToName'] ?? null;
        $data = $message['data'] ?? [];
        $ccTargets = $data['cc'] ?? [];
        $bccTargets = $data['bcc'] ?? [];
        $cc = [];
        $bcc = [];

        if (\count($ccTargets) > 0) {
            $ccTargets = $dbForProject->find('targets', [
                Query::equal('$id', $ccTargets),
                Query::limit(\count($ccTargets)),
            ]);
            foreach ($ccTargets as $ccTarget) {
                $cc[] = ['email' => $ccTarget['identifier']];
            }
        }

        if (\count($bccTargets) > 0) {
            $bccTargets = $dbForProject->find('targets', [
                Query::equal('$id', $bccTargets),
                Query::limit(\count($bccTargets)),
            ]);
            foreach ($bccTargets as $bccTarget) {
                $bcc[] = ['email' => $bccTarget['identifier']];
            }
        }

        $to = $message['to'];
        $subject = $data['subject'];
        $content = $data['content'];
        $html = $data['html'] ?? false;

        return new Email($to, $subject, $content, $fromName, $fromEmail, $replyToName, $replyToEmail, $cc, $bcc, null, $html);
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
        $data = $message['data']['data'] ?? null;
        $action = $message['data']['action'] ?? null;
        $sound = $message['data']['sound'] ?? null;
        $icon = $message['data']['icon'] ?? null;
        $color = $message['data']['color'] ?? null;
        $tag = $message['data']['tag'] ?? null;
        $badge = $message['data']['badge'] ?? null;

        return new Push($to, $title, $body, $data, $action, $sound, $icon, $color, $tag, $badge);
    }
}
