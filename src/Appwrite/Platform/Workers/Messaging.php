<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\StatsUsage;
use Appwrite\Messaging\Status as MessageStatus;
use Swoole\Runtime;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Adapter\Email\Resend;
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Adapter\Push\APNS;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Adapter\Push\FCM;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\Fast2SMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\Inforu;
use Utopia\Messaging\Adapter\SMS\Mock;
use Utopia\Messaging\Adapter\SMS\Msg91;
use Utopia\Messaging\Adapter\SMS\Telesign;
use Utopia\Messaging\Adapter\SMS\TextMagic;
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Adapter\SMS\Vonage;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Priority;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\System\System;

use function Swoole\Coroutine\batch;

class Messaging extends Action
{
    private ?Local $localDevice = null;

    private ?SMSAdapter $adapter = null;

    public static function getName(): string
    {
        return 'messaging';
    }

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this
            ->desc('Messaging worker')
            ->inject('message')
            ->inject('project')
            ->inject('log')
            ->inject('dbForProject')
            ->inject('deviceForFiles')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param Log $log
     * @param Database $dbForProject
     * @param Device $deviceForFiles
     * @param StatsUsage $queueForStatsUsage
     * @return void
     * @throws \Exception
     */
    public function action(
        Message $message,
        Document $project,
        Log $log,
        Database $dbForProject,
        Device $deviceForFiles,
        StatsUsage $queueForStatsUsage
    ): void {
        Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';

        switch ($type) {
            case MESSAGE_SEND_TYPE_INTERNAL:
                $message = new Document($payload['message'] ?? []);
                $recipients = $payload['recipients'] ?? [];

                $this->sendInternalSMSMessage($message, $project, $recipients, $log);
                break;
            case MESSAGE_SEND_TYPE_EXTERNAL:
                $message = $dbForProject->getDocument('messages', $payload['messageId']);

                $this->sendExternalMessage($dbForProject, $message, $deviceForFiles, $project, $queueForStatsUsage);
                break;
            default:
                throw new \Exception('Unknown message type: ' . $type);
        }
    }

    private function sendExternalMessage(
        Database $dbForProject,
        Document $message,
        Device $deviceForFiles,
        Document $project,
        StatsUsage $queueForStatsUsage
    ): void {
        $topicIds = $message->getAttribute('topics', []);
        $targetIds = $message->getAttribute('targets', []);
        $userIds = $message->getAttribute('users', []);
        $providerType = $message->getAttribute('providerType');

        /**
         * @var array<Document> $allTargets
         */
        $allTargets = [];

        if (\count($topicIds) > 0) {
            $topics = $dbForProject->find('topics', [
                Query::equal('$id', $topicIds),
                Query::limit(\count($topicIds)),
            ]);
            foreach ($topics as $topic) {
                $targets = \array_filter($topic->getAttribute('targets'), function (Document $target) use ($providerType) {
                    return $target->getAttribute('providerType') === $providerType;
                });

                \array_push($allTargets, ...$targets);
            }
        }

        if (\count($userIds) > 0) {
            $users = $dbForProject->find('users', [
                Query::equal('$id', $userIds),
                Query::limit(\count($userIds)),
            ]);
            foreach ($users as $user) {
                $targets = \array_filter($user->getAttribute('targets'), function (Document $target) use ($providerType) {
                    return $target->getAttribute('providerType') === $providerType;
                });

                \array_push($allTargets, ...$targets);
            }
        }

        if (\count($targetIds) > 0) {
            $targets = $dbForProject->find('targets', [
                Query::equal('$id', $targetIds),
                Query::equal('providerType', [$providerType]),
                Query::limit(\count($targetIds)),
            ]);

            \array_push($allTargets, ...$targets);
        }

        if (empty($allTargets)) {
            $dbForProject->updateDocument('messages', $message->getId(), $message->setAttributes([
                'status' => MessageStatus::FAILED,
                'deliveryErrors' => ['No valid recipients found.']
            ]));

            Console::warning('No valid recipients found.');
            return;
        }

        $default = $dbForProject->findOne('providers', [
            Query::equal('enabled', [true]),
            Query::equal('type', [$providerType]),
        ]);

        if ($default->isEmpty()) {
            $dbForProject->updateDocument('messages', $message->getId(), $message->setAttributes([
                'status' => MessageStatus::FAILED,
                'deliveryErrors' => ['No enabled provider found.']
            ]));

            Console::warning('No enabled provider found.');
            return;
        }

        /**
         * @var array<string, array<string, null>> $identifiers
         */
        $identifiers = [];

        /**
         * @var array<Document> $providers
         */
        $providers = [
            $default->getId() => $default
        ];

        foreach ($allTargets as $target) {
            $providerId = $target->getAttribute('providerId');

            if (!$providerId) {
                $providerId = $default->getId();
            }

            if ($providerId) {
                if (!\array_key_exists($providerId, $identifiers)) {
                    $identifiers[$providerId] = [];
                }
                // Use null as value to avoid duplicate keys
                $identifiers[$providerId][$target->getAttribute('identifier')] = null;
            }
        }

        /**
         * @var array<array> $results
         */
        $results = batch(\array_map(function ($providerId) use ($identifiers, &$providers, $default, $message, $dbForProject, $deviceForFiles, $project, $queueForStatsUsage) {
            return function () use ($providerId, $identifiers, &$providers, $default, $message, $dbForProject, $deviceForFiles, $project, $queueForStatsUsage) {
                if (\array_key_exists($providerId, $providers)) {
                    $provider = $providers[$providerId];
                } else {
                    $provider = $dbForProject->getDocument('providers', $providerId);

                    if ($provider->isEmpty() || !$provider->getAttribute('enabled')) {
                        $provider = $default;
                    } else {
                        $providers[$providerId] = $provider;
                    }
                }

                $identifiersForProvider = $identifiers[$providerId];

                $adapter = match ($provider->getAttribute('type')) {
                    MESSAGE_TYPE_SMS => $this->getSmsAdapter($provider),
                    MESSAGE_TYPE_PUSH => $this->getPushAdapter($provider),
                    MESSAGE_TYPE_EMAIL => $this->getEmailAdapter($provider),
                    default => throw new \Exception('Provider with the requested ID is of the incorrect type')
                };

                $batches = \array_chunk(
                    \array_keys($identifiersForProvider),
                    $adapter->getMaxMessagesPerRequest()
                );

                return batch(\array_map(function ($batch) use ($message, $provider, $adapter, $dbForProject, $deviceForFiles, $project, $queueForStatsUsage) {
                    return function () use ($batch, $message, $provider, $adapter, $dbForProject, $deviceForFiles, $project, $queueForStatsUsage) {
                        $deliveredTotal = 0;
                        $deliveryErrors = [];
                        $messageData = clone $message;
                        $messageData->setAttribute('to', $batch);

                        $data = match ($provider->getAttribute('type')) {
                            MESSAGE_TYPE_SMS => $this->buildSmsMessage($messageData, $provider),
                            MESSAGE_TYPE_PUSH => $this->buildPushMessage($messageData),
                            MESSAGE_TYPE_EMAIL => $this->buildEmailMessage($dbForProject, $messageData, $provider, $deviceForFiles, $project),
                            default => throw new \Exception('Provider with the requested ID is of the incorrect type')
                        };

                        try {
                            $response = $adapter->send($data);
                            $deliveredTotal += $response['deliveredTo'];
                            foreach ($response['results'] as $result) {
                                if ($result['status'] === 'failure') {
                                    $deliveryErrors[] = "Failed sending to target {$result['recipient']} with error: {$result['error']}";
                                }

                                // Deleting push targets when token has expired.
                                if (($result['error'] ??  '') === 'Expired device token') {
                                    $target = $dbForProject->findOne('targets', [
                                        Query::equal('identifier', [$result['recipient']])
                                    ]);

                                    if (!$target->isEmpty()) {
                                        $dbForProject->updateDocument(
                                            'targets',
                                            $target->getId(),
                                            $target->setAttribute('expired', true)
                                        );
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            $deliveryErrors[] = 'Failed sending to targets with error: ' . $e->getMessage();
                        } finally {
                            $errorTotal = \count($deliveryErrors);
                            $queueForStatsUsage
                                ->setProject($project)
                                ->addMetric(METRIC_MESSAGES, ($deliveredTotal + $errorTotal))
                                ->addMetric(METRIC_MESSAGES_SENT, $deliveredTotal)
                                ->addMetric(METRIC_MESSAGES_FAILED, $errorTotal)
                                ->addMetric(str_replace('{type}', $provider->getAttribute('type'), METRIC_MESSAGES_TYPE), ($deliveredTotal + $errorTotal))
                                ->addMetric(str_replace('{type}', $provider->getAttribute('type'), METRIC_MESSAGES_TYPE_SENT), $deliveredTotal)
                                ->addMetric(str_replace('{type}', $provider->getAttribute('type'), METRIC_MESSAGES_TYPE_FAILED), $errorTotal)
                                ->addMetric(str_replace(['{type}', '{provider}'], [$provider->getAttribute('type'), $provider->getAttribute('provider')], METRIC_MESSAGES_TYPE_PROVIDER), ($deliveredTotal + $errorTotal))
                                ->addMetric(str_replace(['{type}', '{provider}'], [$provider->getAttribute('type'), $provider->getAttribute('provider')], METRIC_MESSAGES_TYPE_PROVIDER_SENT), $deliveredTotal)
                                ->addMetric(str_replace(['{type}', '{provider}'], [$provider->getAttribute('type'), $provider->getAttribute('provider')], METRIC_MESSAGES_TYPE_PROVIDER_FAILED), $errorTotal)
                                ->trigger();

                            return [
                                'deliveredTotal' => $deliveredTotal,
                                'deliveryErrors' => $deliveryErrors,
                            ];
                        }
                    };
                }, $batches));
            };
        }, \array_keys($identifiers)));

        $results = \array_merge(...$results);

        $deliveredTotal = 0;
        $deliveryErrors = [];

        foreach ($results as $result) {
            $deliveredTotal += $result['deliveredTotal'];
            $deliveryErrors = \array_merge($deliveryErrors, $result['deliveryErrors']);
        }

        if (empty($deliveryErrors) && $deliveredTotal === 0) {
            $deliveryErrors[] = 'Unknown error';
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

        // Delete any attachments that were downloaded to local storage
        if ($provider->getAttribute('type') === MESSAGE_TYPE_EMAIL) {
            if ($deviceForFiles->getType() === Storage::DEVICE_LOCAL) {
                return;
            }

            $data = $message->getAttribute('data');
            $attachments = $data['attachments'] ?? [];

            foreach ($attachments as $attachment) {
                $bucketId = $attachment['bucketId'];
                $fileId = $attachment['fileId'];

                $bucket = $dbForProject->getDocument('buckets', $bucketId);
                if ($bucket->isEmpty()) {
                    throw new \Exception('Storage bucket with the requested ID could not be found');
                }

                $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
                if ($file->isEmpty()) {
                    throw new \Exception('Storage file with the requested ID could not be found');
                }

                $path = $file->getAttribute('path', '');

                if ($this->getLocalDevice($project)->exists($path)) {
                    $this->getLocalDevice($project)->delete($path);
                }
            }
        }
    }

    private function sendInternalSMSMessage(Document $message, Document $project, array $recipients, Log $log): void
    {
        if ($this->adapter === null) {
            $this->adapter = $this->createInternalSMSAdapter();
        }

        if ($this->adapter === null) {
            Console::warning('Skipped SMS processing. SMS adapter is not set.');
            return;
        }

        if ($project->isEmpty()) {
            throw new \Exception('Project not set in payload');
        }

        Console::log('Processing project: ' . $project->getId());
        $denyList = System::getEnv('_APP_SMS_PROJECTS_DENY_LIST', '');
        $denyList = explode(',', $denyList);
        if (\in_array($project->getId(), $denyList)) {
            Console::error('Project is in the deny list. Skipping...');
            return;
        }

        $from = System::getEnv('_APP_SMS_FROM', '');
        $sms = new SMS(
            $recipients,
            $message->getAttribute('data')['content'],
            $from
        );

        try {
            $result = $this->adapter->send($sms);
        } catch (\Throwable $th) {
            throw new \Exception('Failed sending to targets with error: ' . $th->getMessage());
        }
    }


    private function getSmsAdapter(Document $provider): ?SMSAdapter
    {
        $credentials = $provider->getAttribute('credentials');

        return match ($provider->getAttribute('provider')) {
            'mock' => (new Mock('username', 'password'))->setEndpoint('http://request-catcher-sms:5000/'),
            'twilio' => new Twilio(
                $credentials['accountSid'] ?? '',
                $credentials['authToken'] ?? '',
                null,
                $credentials['messagingServiceSid'] ?? null
            ),
            'textmagic' => new TextMagic(
                $credentials['username'] ?? '',
                $credentials['apiKey'] ?? ''
            ),
            'telesign' => new Telesign(
                $credentials['customerId'] ?? '',
                $credentials['apiKey'] ?? ''
            ),
            'msg91' => new Msg91(
                $credentials['senderId'] ?? '',
                $credentials['authKey'] ?? '',
                $credentials['templateId'] ?? ''
            ),
            'vonage' => new Vonage(
                $credentials['apiKey'] ?? '',
                $credentials['apiSecret'] ??  ''
            ),
            'fast2sms' => new Fast2SMS(
                $credentials['apiKey'] ?? '',
                $credentials['senderId'] ?? '',
                $credentials['messageId'] ?? '',
                $credentials['useDLT'] ?? true
            ),
            'inforu' => new Inforu(
                $credentials['senderId'] ?? '',
                $credentials['apiKey'] ?? '',
            ),
            default => null
        };
    }

    private function getPushAdapter(Document $provider): ?PushAdapter
    {
        $credentials = $provider->getAttribute('credentials');
        $options = $provider->getAttribute('options');

        return match ($provider->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
            'apns' => new APNS(
                $credentials['authKey'] ?? '',
                $credentials['authKeyId'] ?? '',
                $credentials['teamId'] ?? '',
                $credentials['bundleId'] ?? '',
                $options['sandbox'] ?? false
            ),
            'fcm' => new FCM(\json_encode($credentials['serviceAccountJSON'])),
            default => null
        };
    }

    private function getEmailAdapter(Document $provider): ?EmailAdapter
    {
        $credentials = $provider->getAttribute('credentials', []);
        $options = $provider->getAttribute('options', []);
        $apiKey = $credentials['apiKey'] ?? '';

        return match ($provider->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
            'smtp' => new SMTP(
                $credentials['host'] ??  '',
                $credentials['port'] ?? 25,
                $credentials['username'] ?? '',
                $credentials['password'] ?? '',
                $options['encryption'] ?? '',
                $options['autoTLS'] ??  false,
                $options['mailer'] ??  '',
            ),
            'mailgun' => new Mailgun(
                $apiKey,
                $credentials['domain'] ?? '',
                $credentials['isEuRegion'] ?? false
            ),
            'sendgrid' => new Sendgrid($apiKey),
            'resend' => new Resend($apiKey),
            default => null
        };
    }

    private function buildEmailMessage(
        Database $dbForProject,
        Document $message,
        Document $provider,
        Device $deviceForFiles,
        Document $project,
    ): Email {
        $fromName = $provider['options']['fromName'] ?? null;
        $fromEmail = $provider['options']['fromEmail'] ?? null;
        $replyToEmail = $provider['options']['replyToEmail'] ?? null;
        $replyToName = $provider['options']['replyToName'] ?? null;
        $data = $message['data'] ?? [];
        $ccTargets = $data['cc'] ?? [];
        $bccTargets = $data['bcc'] ?? [];
        $cc = [];
        $bcc = [];
        $attachments = $data['attachments'] ?? [];

        if (!empty($ccTargets)) {
            $ccTargets = $dbForProject->find('targets', [
                Query::equal('$id', $ccTargets),
                Query::limit(\count($ccTargets)),
            ]);
            foreach ($ccTargets as $ccTarget) {
                $cc[] = ['email' => $ccTarget['identifier']];
            }
        }

        if (!empty($bccTargets)) {
            $bccTargets = $dbForProject->find('targets', [
                Query::equal('$id', $bccTargets),
                Query::limit(\count($bccTargets)),
            ]);
            foreach ($bccTargets as $bccTarget) {
                $bcc[] = ['email' => $bccTarget['identifier']];
            }
        }

        if (!empty($attachments)) {
            foreach ($attachments as &$attachment) {
                $bucketId = $attachment['bucketId'];
                $fileId = $attachment['fileId'];

                $bucket = $dbForProject->getDocument('buckets', $bucketId);
                if ($bucket->isEmpty()) {
                    throw new \Exception('Storage bucket with the requested ID could not be found');
                }

                $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
                if ($file->isEmpty()) {
                    throw new \Exception('Storage file with the requested ID could not be found');
                }

                $mimes = Config::getParam('storage-mimes');
                $path = $file->getAttribute('path', '');

                if (!$deviceForFiles->exists($path)) {
                    throw new \Exception('File not found in ' . $path);
                }

                $contentType = 'text/plain';

                if (\in_array($file->getAttribute('mimeType'), $mimes)) {
                    $contentType = $file->getAttribute('mimeType');
                }

                if ($deviceForFiles->getType() !== Storage::DEVICE_LOCAL) {
                    $deviceForFiles->transfer($path, $path, $this->getLocalDevice($project));
                }

                $attachment = new Attachment(
                    $file->getAttribute('name'),
                    $path,
                    $contentType
                );
            }
        }

        $to = $message['to'];
        $subject = $data['subject'];
        $content = $data['content'];
        $html = $data['html'] ?? false;

        // For SMTP, move all recipients to BCC and use default recipient in TO field
        if ($provider->getAttribute('provider') === 'smtp') {
            foreach ($to as $recipient) {
                $bcc[] = ['email' => $recipient];
            }
            $to = [];
        }

        return new Email(
            $to,
            $subject,
            $content,
            $fromName,
            $fromEmail,
            $replyToName,
            $replyToEmail,
            $cc,
            $bcc,
            $attachments,
            $html
        );
    }

    private function buildSmsMessage(Document $message, Document $provider): SMS
    {
        $to = $message['to'];
        $content = $message['data']['content'];
        $from = $provider['options']['from'];

        return new SMS(
            $to,
            $content,
            $from
        );
    }

    private function buildPushMessage(Document $message): Push
    {
        $to = $message['to'];
        $title = $message['data']['title'] ?? null;
        $body = $message['data']['body'] ?? null;
        $data = $message['data']['data'] ?? null;
        $action = $message['data']['action'] ?? null;
        $image = $message['data']['image']['url'] ?? null;
        $sound = $message['data']['sound'] ?? null;
        $icon = $message['data']['icon'] ?? null;
        $color = $message['data']['color'] ?? null;
        $tag = $message['data']['tag'] ?? null;
        $badge = $message['data']['badge'] ?? null;
        $contentAvailable = $message['data']['contentAvailable'] ?? null;
        $critical = $message['data']['critical'] ?? null;
        $priority = $message['data']['priority'] ?? null;

        if ($title === '') {
            $title = null;
        }
        if ($body === '') {
            $body = null;
        }
        if ($priority !== null) {
            $priority = $priority === 'high'
                ? Priority::HIGH
                : Priority::NORMAL;
        }

        return new Push(
            $to,
            $title,
            $body,
            $data,
            $action,
            $sound,
            $image,
            $icon,
            $color,
            $tag,
            $badge,
            $contentAvailable,
            $critical,
            $priority
        );
    }

    private function getLocalDevice($project): Local
    {
        if ($this->localDevice === null) {
            $this->localDevice = new Local(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
        }

        return $this->localDevice;
    }

    private function createInternalSMSAdapter(): ?SMSAdapter
    {
        if (empty(System::getEnv('_APP_SMS_PROVIDER')) || empty(System::getEnv('_APP_SMS_FROM'))) {
            Console::warning('Skipped SMS processing. Missing "_APP_SMS_PROVIDER" or "_APP_SMS_FROM" environment variables.');
            return null;
        }

        $providers = System::getEnv('_APP_SMS_PROVIDER', '');

        $dsns = [];
        if (!empty($providers)) {
            $providers = explode(',', $providers);
            foreach ($providers as $provider) {
                $dsns[] = new DSN($provider);
            }
        }

        if (count($dsns) === 1) {
            $provider = $this->createProviderFromDSN($dsns[0]);
            $adapter = $this->getSmsAdapter($provider);
            return $adapter;
        }

        $defaultDSN = null;
        $localDSNs = [];

        /** @var DSN $dsn */
        foreach ($dsns as $dsn) {
            if ($dsn->getParam('local', '') === 'default') {
                $defaultDSN = $dsn;
            } else {
                $localDSNs[] = $dsn;
            }
        }

        if ($defaultDSN === null) {
            throw new \Exception('No default SMS provider found');
        }

        $defaultProvider = $this->createProviderFromDSN($defaultDSN);
        $adapter = $this->getSmsAdapter($defaultProvider);
        $geosms = new GEOSMS($adapter);

        /** @var DSN $localDSN */
        foreach ($localDSNs as $localDSN) {
            try {
                $provider = $this->createProviderFromDSN($localDSN);
                $adapter = $this->getSmsAdapter($provider);
            } catch (\Exception) {
                Console::warning('Unable to create adapter: ' . $localDSN->getHost());
                continue;
            }

            $callingCode = $localDSN->getParam('local', '');
            if (empty($callingCode)) {
                Console::warning('Unable to register adapter: ' . $localDSN->getHost() . '. Missing `local` parameter.');
                continue;
            }

            $geosms->setLocal($callingCode, $adapter);
        }
        return $geosms;
    }

    private function createProviderFromDSN(DSN $dsn): Document
    {
        $host = $dsn->getHost();
        $password = $dsn->getPassword();
        $user = $dsn->getUser();
        $from = System::getEnv('_APP_SMS_FROM');

        $provider = new Document([
            '$id' => ID::unique(),
            'provider' => $host,
            'type' => MESSAGE_TYPE_SMS,
            'name' => 'Internal SMS',
            'enabled' => true,
            'credentials' => match ($host) {
                'twilio' => [
                    'accountSid' => $user,
                    'authToken' => $password,
                    // Twilio Messaging Service SIDs always start with MG
                    // https://www.twilio.com/docs/messaging/services
                    'messagingServiceSid' => \str_starts_with($from, 'MG') ? $from : null
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
                    'authKey' => $password,
                    'templateId' => $dsn->getParam('templateId', $from),
                ],
                'vonage' => [
                    'apiKey' => $user,
                    'apiSecret' => $password
                ],
                'fast2sms' => [
                    'senderId' => $user,
                    'apiKey' => $password,
                    'messageId' => $dsn->getParam('messageId'),
                    'useDLT' => $dsn->getParam('useDLT'),
                ],
                'inforu' => [
                    'senderId' => $user,
                    'apiKey' => $password,
                ],
                default => null
            },
            'options' => match ($host) {
                'twilio' => [
                    'from' => \str_starts_with($from, 'MG') ? null : $from
                ],
                default => [
                    'from' => $from
                ]
            }
        ]);

        return $provider;
    }
}
