<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Usage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Messaging\Status as MessageStatus;
use Appwrite\Usage\Context as UsageContext;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Swoole\Runtime;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Lock\Semaphore;
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
use Utopia\Span\Span;
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
            ->inject('publisherForUsage')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param Log $log
     * @param Database $dbForProject
     * @param Device $deviceForFiles
     * @param UsagePublisher $publisherForUsage
     * @return void
     * @throws \Exception
     */
    public function action(
        Message $message,
        Document $project,
        Log $log,
        Database $dbForProject,
        Device $deviceForFiles,
        UsagePublisher $publisherForUsage
    ): void {
        Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';

        Span::add('message.type', $type);

        switch ($type) {
            case MESSAGE_SEND_TYPE_INTERNAL:
                $message = new Document($payload['message'] ?? []);
                $recipients = $payload['recipients'] ?? [];

                $this->sendInternalSMSMessage($message, $project, $recipients, $log);
                break;
            case MESSAGE_SEND_TYPE_EXTERNAL:
                $message = $dbForProject->getDocument('messages', $payload['messageId']);

                $this->sendExternalMessage($dbForProject, $message, $deviceForFiles, $project, $publisherForUsage);
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
        UsagePublisher $publisherForUsage
    ): void {
        $status = $message->getAttribute('status');

        // Idempotency guard: queue redelivery can hand us a message that already finished.
        if (\in_array($status, [MessageStatus::SENT, MessageStatus::FAILED], true)) {
            Span::add('message.skipped', 'already_processed');
            return;
        }

        $topicIds = $message->getAttribute('topics', []);
        $targetIds = $message->getAttribute('targets', []);
        $userIds = $message->getAttribute('users', []);
        $providerType = $message->getAttribute('providerType');

        $default = $dbForProject->findOne('providers', [
            Query::equal('enabled', [true]),
            Query::equal('type', [$providerType]),
        ]);

        if ($default->isEmpty()) {
            $dbForProject->updateDocument('messages', $message->getId(), $message->setAttributes([
                'status' => MessageStatus::FAILED,
                'deliveryErrors' => ['No enabled provider found.']
            ]));

            Span::add('message.skipped', 'no_enabled_provider');
            return;
        }

        /**
         * Resolved providers cached for the lifetime of this job, keyed by provider id.
         * Seeded with the default provider so most sends never touch the providers collection.
         *
         * @var array<string, Document> $providers
         */
        $providers = [
            $default->getId() => $default,
        ];

        $semaphore = new Semaphore(MESSAGE_SEND_CONCURRENCY);

        $deliveredTotal = 0;
        $failedTotal = 0;
        $deliveryErrors = [];
        $hasRecipients = false;

        foreach ($this->streamRecipients($dbForProject, $topicIds, $userIds, $targetIds, $providerType, $default) as $page) {
            $hasRecipients = true;

            /**
             * @var array<callable> $tasks
             */
            $tasks = [];

            foreach ($page as $providerId => $identifiers) {
                $provider = $this->resolveProvider($dbForProject, $providerId, $providers, $default);
                $resolvedProviderType = $provider->getAttribute('type');

                $adapter = match ($resolvedProviderType) {
                    MESSAGE_TYPE_SMS => $this->getSmsAdapter($provider),
                    MESSAGE_TYPE_PUSH => $this->getPushAdapter($provider),
                    MESSAGE_TYPE_EMAIL => $this->getEmailAdapter($provider),
                    default => throw new \Exception('Provider with the requested ID is of the incorrect type')
                };

                $batches = \array_chunk(
                    \array_keys($identifiers),
                    $adapter->getMaxMessagesPerRequest()
                );

                foreach ($batches as $batch) {
                    $tasks[] = fn (): array => $semaphore->withLock(
                        fn (): array => $this->sendBatch(
                            $batch,
                            $message,
                            $provider,
                            $resolvedProviderType,
                            $adapter,
                            $dbForProject,
                            $deviceForFiles,
                            $project,
                            $publisherForUsage
                        )
                    );
                }
            }

            /**
             * @var array<array{delivered: int, recipients: int, errors: array<string>}> $results
             */
            $results = batch($tasks);

            foreach ($results as $result) {
                $deliveredTotal += $result['delivered'];
                $failedTotal += $result['recipients'] - $result['delivered'];

                foreach ($result['errors'] as $error) {
                    if (\count($deliveryErrors) >= MESSAGE_DELIVERY_ERRORS_LIMIT) {
                        break;
                    }

                    $deliveryErrors[] = $error;
                }
            }
        }

        if (!$hasRecipients) {
            $dbForProject->updateDocument('messages', $message->getId(), $message->setAttributes([
                'status' => MessageStatus::FAILED,
                'deliveryErrors' => ['No valid recipients found.']
            ]));

            Span::add('message.skipped', 'no_valid_recipients');
            return;
        }

        if (empty($deliveryErrors) && $deliveredTotal === 0) {
            $deliveryErrors[] = 'Unknown error';
        }

        $hasFailures = $failedTotal > 0 || \count($deliveryErrors) > 0;
        $message->setAttribute('status', $hasFailures ? MessageStatus::FAILED : MessageStatus::SENT);
        $message->setAttribute('deliveryErrors', $deliveryErrors);

        Span::add('message.delivered_total', $deliveredTotal);
        Span::add('message.errors_total', $failedTotal);

        $message->removeAttribute('to');

        foreach ($providers as $provider) {
            $message->setAttribute('search', "{$message->getAttribute('search')} {$provider->getAttribute('name')} {$provider->getAttribute('provider')} {$provider->getAttribute('type')}");
        }

        $message->setAttribute('deliveredTotal', $deliveredTotal);
        $message->setAttribute('deliveredAt', DateTime::now());

        $dbForProject->updateDocument('messages', $message->getId(), new Document([
            'deliveryErrors' => $message->getAttribute('deliveryErrors'),
            'status' => $message->getAttribute('status'),
            'search' => $message->getAttribute('search'),
            'deliveredTotal' => $message->getAttribute('deliveredTotal'),
            'deliveredAt' => $message->getAttribute('deliveredAt'),
        ]));

        // Delete any attachments that were downloaded to local storage
        if ($providerType === MESSAGE_TYPE_EMAIL) {
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

    /**
     * Stream a message's recipients in bounded pages, grouped by provider and deduplicated by identifier within each page.
     *
     * Peak memory is O(MESSAGE_RECIPIENTS_PAGE_SIZE), never O(topic size): topics are walked through the
     * subscribers collection with cursor pagination rather than reading the topic's `targets` attribute, which
     * triggers the subQueryTopicTargets filter and loads up to APP_LIMIT_SUBSCRIBERS_SUBQUERY rows at once.
     *
     * @param array<string> $topicIds
     * @param array<string> $userIds
     * @param array<string> $targetIds
     * @return \Generator<array<string, array<string, null>>>
     * @throws \Exception
     */
    private function streamRecipients(
        Database $dbForProject,
        array $topicIds,
        array $userIds,
        array $targetIds,
        string $providerType,
        Document $default
    ): \Generator {
        if (\count($topicIds) > 0) {
            $topics = $dbForProject->find('topics', [
                Query::select(['$sequence']),
                Query::equal('$id', $topicIds),
                Query::limit(\count($topicIds)),
            ]);

            foreach ($topics as $topic) {
                $cursor = null;

                do {
                    $queries = [
                        Query::equal('topicInternalId', [$topic->getSequence()]),
                        Query::equal('providerType', [$providerType]),
                        Query::select(['$sequence', 'targetInternalId']),
                        Query::orderAsc('$sequence'),
                        Query::limit(MESSAGE_RECIPIENTS_PAGE_SIZE),
                    ];

                    if ($cursor !== null) {
                        $queries[] = Query::cursorAfter($cursor);
                    }

                    $subscribers = $dbForProject->getAuthorization()->skip(
                        fn () => $dbForProject->find('subscribers', $queries)
                    );

                    $count = \count($subscribers);

                    if ($count === 0) {
                        break;
                    }

                    $cursor = $subscribers[$count - 1];

                    $targetInternalIds = \array_map(
                        fn (Document $subscriber) => $subscriber->getAttribute('targetInternalId'),
                        $subscribers
                    );

                    $targets = $dbForProject->skipValidation(
                        fn () => $dbForProject->getAuthorization()->skip(
                            fn () => $dbForProject->find('targets', [
                                Query::equal('$sequence', $targetInternalIds),
                                Query::select(['providerId', 'identifier']),
                                Query::limit(\count($targetInternalIds)),
                            ])
                        )
                    );

                    yield $this->groupTargetsByProvider($targets, $default);
                } while ($count === MESSAGE_RECIPIENTS_PAGE_SIZE);
            }
        }

        if (\count($userIds) > 0) {
            $cursor = null;

            do {
                $queries = [
                    Query::equal('userId', $userIds),
                    Query::equal('providerType', [$providerType]),
                    Query::select(['$sequence', 'providerId', 'identifier']),
                    Query::orderAsc('$sequence'),
                    Query::limit(MESSAGE_RECIPIENTS_PAGE_SIZE),
                ];

                if ($cursor !== null) {
                    $queries[] = Query::cursorAfter($cursor);
                }

                $targets = $dbForProject->find('targets', $queries);
                $count = \count($targets);

                if ($count === 0) {
                    break;
                }

                $cursor = $targets[$count - 1];

                yield $this->groupTargetsByProvider($targets, $default);
            } while ($count === MESSAGE_RECIPIENTS_PAGE_SIZE);
        }

        if (\count($targetIds) > 0) {
            $cursor = null;

            do {
                $queries = [
                    Query::equal('$id', $targetIds),
                    Query::equal('providerType', [$providerType]),
                    Query::select(['$sequence', 'providerId', 'identifier']),
                    Query::orderAsc('$sequence'),
                    Query::limit(MESSAGE_RECIPIENTS_PAGE_SIZE),
                ];

                if ($cursor !== null) {
                    $queries[] = Query::cursorAfter($cursor);
                }

                $targets = $dbForProject->find('targets', $queries);
                $count = \count($targets);

                if ($count === 0) {
                    break;
                }

                $cursor = $targets[$count - 1];

                yield $this->groupTargetsByProvider($targets, $default);
            } while ($count === MESSAGE_RECIPIENTS_PAGE_SIZE);
        }
    }

    /**
     * Group a page of target documents by provider id, deduplicating identifiers within the page.
     *
     * @param array<Document> $targets
     * @return array<string, array<string, null>>
     */
    private function groupTargetsByProvider(array $targets, Document $default): array
    {
        /**
         * @var array<string, array<string, null>> $identifiers
         */
        $identifiers = [];

        foreach ($targets as $target) {
            $providerId = $target->getAttribute('providerId') ?: $default->getId();

            if (!\array_key_exists($providerId, $identifiers)) {
                $identifiers[$providerId] = [];
            }

            // Null values keep identifiers unique without a second lookup structure.
            $identifiers[$providerId][$target->getAttribute('identifier')] = null;
        }

        return $identifiers;
    }

    /**
     * Resolve and cache a provider for the lifetime of a send job, falling back to the default provider.
     *
     * @param array<string, Document> $providers
     */
    private function resolveProvider(
        Database $dbForProject,
        string $providerId,
        array &$providers,
        Document $default
    ): Document {
        if (\array_key_exists($providerId, $providers)) {
            return $providers[$providerId];
        }

        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty() || !$provider->getAttribute('enabled')) {
            return $default;
        }

        $providers[$providerId] = $provider;

        return $provider;
    }

    /**
     * Send a single adapter-sized batch and report delivery counts plus a bounded error list.
     *
     * @param array<string> $batch
     * @return array{delivered: int, recipients: int, errors: array<string>}
     */
    private function sendBatch(
        array $batch,
        Document $message,
        Document $provider,
        string $providerType,
        EmailAdapter|SMSAdapter|PushAdapter $adapter,
        Database $dbForProject,
        Device $deviceForFiles,
        Document $project,
        UsagePublisher $publisherForUsage
    ): array {
        $recipients = \count($batch);
        $delivered = 0;
        $errors = [];

        $messageData = clone $message;
        $messageData->setAttribute('to', $batch);

        $data = match ($providerType) {
            MESSAGE_TYPE_SMS => $this->buildSmsMessage($messageData, $provider),
            MESSAGE_TYPE_PUSH => $this->buildPushMessage($messageData),
            MESSAGE_TYPE_EMAIL => $this->buildEmailMessage($dbForProject, $messageData, $provider, $deviceForFiles, $project),
            default => throw new \Exception('Provider with the requested ID is of the incorrect type')
        };

        try {
            $response = $adapter->send($data);
            $delivered = (int) $response['deliveredTo'];

            foreach ($response['results'] as $result) {
                if ($result['status'] === 'failure' && \count($errors) < MESSAGE_DELIVERY_ERRORS_LIMIT) {
                    $errors[] = "Failed sending to target {$result['recipient']} with error: {$result['error']}";
                }

                // Deleting push targets when token has expired.
                if (($result['error'] ?? '') === 'Expired device token') {
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
            // Whole-batch failure: record one representative error and count every recipient as failed.
            $delivered = 0;
            $errors = ['Failed sending to targets with error: ' . $e->getMessage()];
        } finally {
            $failed = $recipients - $delivered;
            $usage = new UsageContext();
            $usage
                ->addMetric(METRIC_MESSAGES, $recipients)
                ->addMetric(METRIC_MESSAGES_SENT, $delivered)
                ->addMetric(METRIC_MESSAGES_FAILED, $failed)
                ->addMetric(str_replace('{type}', $provider->getAttribute('type'), METRIC_MESSAGES_TYPE), $recipients)
                ->addMetric(str_replace('{type}', $provider->getAttribute('type'), METRIC_MESSAGES_TYPE_SENT), $delivered)
                ->addMetric(str_replace('{type}', $provider->getAttribute('type'), METRIC_MESSAGES_TYPE_FAILED), $failed)
                ->addMetric(str_replace(['{type}', '{provider}'], [$provider->getAttribute('type'), $provider->getAttribute('provider')], METRIC_MESSAGES_TYPE_PROVIDER), $recipients)
                ->addMetric(str_replace(['{type}', '{provider}'], [$provider->getAttribute('type'), $provider->getAttribute('provider')], METRIC_MESSAGES_TYPE_PROVIDER_SENT), $delivered)
                ->addMetric(str_replace(['{type}', '{provider}'], [$provider->getAttribute('type'), $provider->getAttribute('provider')], METRIC_MESSAGES_TYPE_PROVIDER_FAILED), $failed);

            $publisherForUsage->enqueue(new Usage(
                project: $project,
                metrics: $usage->getMetrics(),
            ));
        }

        return [
            'delivered' => $delivered,
            'recipients' => $recipients,
            'errors' => $errors,
        ];
    }

    private function sendInternalSMSMessage(Document $message, Document $project, array $recipients, Log $log): void
    {
        if ($this->adapter === null) {
            $this->adapter = $this->createInternalSMSAdapter();
        }

        if ($this->adapter === null) {
            throw new \Exception('SMS adapter is not set.');
        }

        if ($project->isEmpty()) {
            throw new \Exception('Project not set in payload');
        }

        $denyList = System::getEnv('_APP_SMS_PROJECTS_DENY_LIST', '');
        $denyList = explode(',', $denyList);
        if (\in_array($project->getId(), $denyList)) {
            Span::add('message.skipped', 'project_denied');
            return;
        }

        $from = System::getEnv('_APP_SMS_FROM', '');
        Span::add('message.from', $from);

        try {
            $phoneNumber = PhoneNumberUtil::getInstance()->parse($recipients[0] ?? '');
            Span::add('message.country_code', $phoneNumber->getCountryCode());
        } catch (NumberParseException $e) {
            Span::add('message.country_code', 'unknown');
        }

        $sms = new SMS(
            $recipients,
            $message->getAttribute('data')['content'],
            $from
        );

        $this->adapter->send($sms);
    }


    protected function getSmsAdapter(Document $provider): ?SMSAdapter
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

    protected function getPushAdapter(Document $provider): ?PushAdapter
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

    protected function getEmailAdapter(Document $provider): ?EmailAdapter
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
                continue;
            }

            $callingCode = $localDSN->getParam('local', '');
            if (empty($callingCode)) {
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
