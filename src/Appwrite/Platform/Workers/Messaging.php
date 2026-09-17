<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Usage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Messaging\Provider;
use Appwrite\Messaging\Status as MessageStatus;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Usage\Context as UsageContext;
use Utopia\Cache\Cache;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Lock\Semaphore;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Adapter\SMS\Msg91\MetadataParameter;
use Utopia\Messaging\Adapter\SMS\WhatsApp;
use Utopia\Messaging\Adapter\SMS\WhatsApp\MetadataParameter as WhatsAppMetadataParameter;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Priority;
use Utopia\Platform\Action;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\DeviceType;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

use function Swoole\Coroutine\batch;

class Messaging extends Action
{
    private Provider $provider;

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
            ->inject('dbForProject')
            ->inject('deviceForFiles')
            ->inject('publisherForUsage')
            ->inject('telemetry')
            ->inject('adapterForSMS')
            ->inject('adapterForWhatsApp')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param Database $dbForProject
     * @param Device $deviceForFiles
     * @param UsagePublisher $publisherForUsage
     * @param Telemetry $telemetry
     * @param SMSAdapter|null $adapterForSMS
     * @param SMSAdapter|null $adapterForWhatsApp
     * @param Cache $cache
     * @return void
     * @throws \Exception
     */
    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Device $deviceForFiles,
        UsagePublisher $publisherForUsage,
        Telemetry $telemetry,
        ?SMSAdapter $adapterForSMS,
        ?SMSAdapter $adapterForWhatsApp,
        Cache $cache
    ): void {
        $this->provider = new Provider($telemetry);
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

                $this->sendInternalMessage(
                    $message,
                    $project,
                    $recipients,
                    $publisherForUsage,
                    $adapterForSMS,
                    $adapterForWhatsApp,
                    $cache,
                    $payload['channel'] ?? null,
                    (bool)($payload['fallback'] ?? false)
                );
                break;
            case MESSAGE_SEND_TYPE_EXTERNAL:
                $messageId = $payload['messageId'];
                $message = $dbForProject->getDocument('messages', $messageId);

                // Unique per job so a redelivery cannot reclaim a directory a live job is still
                // reading; the underscore prefix is unreachable, as a bucket ID may not start with one.
                $attachmentsPath = $this->getLocalDevice($project)->getPath('_attachments/' . ID::unique());

                try {
                    if ($message->isEmpty()) {
                        throw new \Exception('Message not found: ' . $messageId);
                    }

                    $this->sendExternalMessage($dbForProject, $message, $deviceForFiles, $project, $publisherForUsage, $attachmentsPath);
                } catch (\Throwable $e) {
                    $this->markFailed($dbForProject, $messageId, $e);

                    throw $e;
                } finally {
                    // Decrypted plaintext must not linger. A failed delete and an absent directory both come
                    // back false, so only a directory that is still there after the attempt is worth
                    // reporting; throwing here would bury whatever the send itself threw.
                    $deviceForLocal = $this->getLocalDevice($project);
                    $deviceForLocal->delete($attachmentsPath, true);

                    if ($deviceForLocal->exists($attachmentsPath)) {
                        Span::add('message.attachments_cleanup_failed', $attachmentsPath);
                    }
                }
                break;
            default:
                throw new \Exception('Unknown message type: ' . $type);
        }
    }

    /**
     * Record a failure for a job that died before writing a terminal status. A failed job is dead-lettered
     * rather than redelivered, so a message left processing stays that way for good.
     */
    private function markFailed(Database $dbForProject, string $messageId, \Throwable $error): void
    {
        try {
            $message = $dbForProject->getDocument('messages', $messageId);

            if ($message->isEmpty() || \in_array($message->getAttribute('status'), [MessageStatus::SENT, MessageStatus::FAILED], true)) {
                return;
            }

            $dbForProject->updateDocument('messages', $messageId, new Document([
                'status' => MessageStatus::FAILED,
                'deliveryErrors' => [$error->getMessage()],
            ]));
        } catch (\Throwable) {
            // Never mask the original failure, which the caller rethrows.
        }
    }

    private function sendExternalMessage(
        Database $dbForProject,
        Document $message,
        Device $deviceForFiles,
        Document $project,
        UsagePublisher $publisherForUsage,
        string $attachmentsPath
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

        Span::add('message.provider_type', $providerType);

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

        // Hoisted out of buildMessage(), which every batch and every retry attempt calls.
        $attachments = $providerType === MESSAGE_TYPE_EMAIL
            ? $this->prepareAttachments($dbForProject, $message, $deviceForFiles, $project, $attachmentsPath)
            : [];

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
            /**
             * @var array<callable> $tasks
             */
            $tasks = [];

            foreach ($page as $providerId => $identifiers) {
                $provider = $this->resolveProvider($dbForProject, $providerId, $providers, $default);
                $resolvedProviderType = $provider->getAttribute('type');

                $adapter = match ($resolvedProviderType) {
                    MESSAGE_TYPE_SMS => $this->provider->sms($provider),
                    MESSAGE_TYPE_PUSH => $this->provider->push($provider),
                    MESSAGE_TYPE_EMAIL => $this->provider->email($provider),
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
                            $project,
                            $publisherForUsage,
                            $attachments
                        )
                    );
                }
            }

            // A page can resolve to zero identifiers (e.g. subscribers whose targetInternalId matches no targets
            // row), so an empty task list must not count as recipients or run batch() for nothing.
            if (empty($tasks)) {
                continue;
            }

            $hasRecipients = true;

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

        Span::add('message.providers', \implode(',', \array_unique(\array_map(
            fn (Document $provider) => $provider->getAttribute('provider'),
            \array_values($providers)
        ))));

        $message->setAttribute('deliveredTotal', $deliveredTotal);
        $message->setAttribute('deliveredAt', DateTime::now());

        $dbForProject->updateDocument('messages', $message->getId(), new Document([
            'deliveryErrors' => $message->getAttribute('deliveryErrors'),
            'status' => $message->getAttribute('status'),
            'search' => $message->getAttribute('search'),
            'deliveredTotal' => $message->getAttribute('deliveredTotal'),
            'deliveredAt' => $message->getAttribute('deliveredAt'),
        ]));
    }

    /**
     * Stream a message's recipients in bounded pages, grouped by provider and deduplicated by identifier within each page.
     *
     * Peak memory is O(MESSAGE_RECIPIENTS_PAGE_SIZE), never O(topic size): topics are walked through the
     * subscribers collection with cursor pagination rather than reading the topic's `targets` attribute, which
     * is capped at APP_LIMIT_SUBSCRIBERS_SUBQUERY and would silently drop the rest of the recipients.
     * Decode filters run regardless of Query::select, so the topic lookup below skips that filter explicitly
     * rather than relying on selecting only $sequence.
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
            $topics = $dbForProject->skipFilters(
                fn () => $dbForProject->find('topics', [
                    Query::select(['$sequence']),
                    Query::equal('$id', $topicIds),
                    Query::limit(\count($topicIds)),
                ]),
                APP_TOPICS_SUBQUERIES
            );

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
            // Cache the fallback under this id too, so a topic full of targets pointing at the same
            // disabled/missing provider does not re-query the providers collection once per page.
            $providers[$providerId] = $default;

            return $default;
        }

        $providers[$providerId] = $provider;

        return $provider;
    }

    /**
     * Send a single adapter-sized batch and report delivery counts plus a bounded error list.
     *
     * Wraps the provider call in a backoff/retry loop that reacts to provider rate limiting and transient
     * failures (see {@see retrySend()}). Accounting stays exact: only still-failing recipients are ever
     * retried, so `delivered` is summed across attempts without double-counting, and `recipients` always
     * reports the original batch size so the caller's `failed = recipients - delivered` holds.
     *
     * @param array<string> $batch
     * @param array<Attachment> $attachments
     * @return array{delivered: int, recipients: int, errors: array<string>}
     */
    private function sendBatch(
        array $batch,
        Document $message,
        Document $provider,
        string $providerType,
        EmailAdapter|SMSAdapter|PushAdapter $adapter,
        Database $dbForProject,
        Document $project,
        UsagePublisher $publisherForUsage,
        array $attachments
    ): array {
        $recipients = \count($batch);

        [
            'delivered' => $delivered,
            'errors' => $errors,
        ] = $this->retrySend($batch, $message, $provider, $providerType, $adapter, $dbForProject, $attachments);

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

        return [
            'delivered' => $delivered,
            'recipients' => $recipients,
            'errors' => $errors,
        ];
    }

    /**
     * Drive a batch through provider sends with exponential backoff, reacting to throttling and transient
     * errors by retrying only the recipients that are still failing.
     *
     * Partitioning per attempt: the provider's per-recipient results are split into delivered (summed into
     * the running total, never retried) and failures. A failure whose error matches {@see isRetryableError()}
     * stays pending for the next attempt; any other failure becomes a terminal error immediately. When
     * `send()` throws, the throw is retryable only if its message matches; otherwise every still-pending
     * recipient is recorded as terminal. The next attempt rebuilds the provider message with only the pending
     * recipients in `to`, so an already-delivered recipient is never re-sent. After {@see MESSAGE_SEND_MAX_RETRIES}
     * attempts, any recipients still pending are flushed to terminal errors.
     *
     * Note: provider `Retry-After` hints are not honored — the agnostic adapter {@see \Utopia\Messaging\Response}
     * exposes only generic per-recipient strings, never a structured retry delay, so we keep the library
     * adapter-agnostic and rely on exponential backoff alone.
     *
     * @param array<string> $batch
     * @param array<Attachment> $attachments
     * @return array{delivered: int, errors: array<string>}
     */
    private function retrySend(
        array $batch,
        Document $message,
        Document $provider,
        string $providerType,
        EmailAdapter|SMSAdapter|PushAdapter $adapter,
        Database $dbForProject,
        array $attachments
    ): array {
        $delivered = 0;
        $errors = [];

        // Recipients still awaiting a successful (or terminal) outcome; shrinks to only the failing ones each attempt.
        $pending = $batch;

        for ($attempt = 1; $attempt <= MESSAGE_SEND_MAX_RETRIES; $attempt++) {
            $hasRetriesLeft = $attempt < MESSAGE_SEND_MAX_RETRIES;

            // Rebuild the provider message scoped to only the still-pending recipients so a partially-delivered
            // batch never re-sends to recipients that already succeeded on an earlier attempt.
            $data = $this->buildMessage($pending, $message, $provider, $providerType, $dbForProject, $attachments);

            $retry = [];

            // The try/catch wraps ONLY the provider send. A whole-batch throw is retryable when transient,
            // otherwise it records one representative terminal error. The previous behaviour of resetting
            // $delivered to 0 on a throw is gone — the retry refactor sums delivered across attempts, and the
            // expired-device-token cleanup below is isolated in its own try so a DB hiccup there can never be
            // misattributed as a send failure.
            try {
                $response = $adapter->send($data);
            } catch (\Throwable $e) {
                if ($hasRetriesLeft && $this->isRetryableError($e->getMessage())) {
                    $retry = $pending;
                } else {
                    $this->recordError($errors, 'Failed sending to targets with error: ' . $e->getMessage());
                }

                $response = null;
            }

            if ($response !== null) {
                $delivered += (int) $response['deliveredTo'];

                foreach ($response['results'] as $result) {
                    if ($result['status'] !== 'failure') {
                        continue;
                    }

                    $error = $result['error'] ?? null;
                    $recipient = $result['recipient'];

                    // Best-effort: deleting push targets when the token has expired. Isolated so a transient DB
                    // error here never affects delivery accounting or the retry decision below.
                    if ($error === 'Expired device token') {
                        try {
                            $target = $dbForProject->findOne('targets', [
                                Query::equal('identifier', [$recipient])
                            ]);

                            if (!$target->isEmpty()) {
                                $dbForProject->updateDocument(
                                    'targets',
                                    $target->getId(),
                                    $target->setAttribute('expired', true)
                                );
                            }
                        } catch (\Throwable) {
                            // Best-effort; must not affect accounting or retries.
                        }
                    }

                    if ($hasRetriesLeft && $this->isRetryableError($error)) {
                        $retry[] = $recipient;
                        continue;
                    }

                    $this->recordError($errors, "Failed sending to target {$recipient} with error: {$error}");
                }
            }

            $pending = $retry;

            if (empty($pending)) {
                break;
            }

            // Exponential backoff with jitter; non-blocking under Swoole so sibling sends keep progressing.
            // Skip a non-positive delay: Swoole\Coroutine::sleep() rejects 0/negative values (which tests
            // produce by overriding the base delay to 0 for speed), and it would otherwise emit a warning.
            $delay = $this->retryDelay() * (2 ** ($attempt - 1));
            $delay += $delay * (\random_int(0, 100) / 1000);
            if ($delay > 0) {
                \Swoole\Coroutine::sleep($delay);
            }
        }

        return [
            'delivered' => $delivered,
            'errors' => $errors,
        ];
    }

    /**
     * Append a delivery error while keeping the retained list bounded by {@see MESSAGE_DELIVERY_ERRORS_LIMIT}.
     *
     * @param array<string> $errors
     */
    private function recordError(array &$errors, string $error): void
    {
        if (\count($errors) >= MESSAGE_DELIVERY_ERRORS_LIMIT) {
            return;
        }

        $errors[] = $error;
    }

    /**
     * Conservatively classify a provider error string as retryable. The agnostic adapter
     * {@see \Utopia\Messaging\Response} returns only free-form error strings, so string matching is the only
     * provider-agnostic signal available. The pattern list is intentionally narrow — throttling, rate limits,
     * quota, service-unavailable and timeout phrasing — so permanent failures (e.g. invalid recipients) are
     * never retried.
     */
    private function isRetryableError(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }

        $patterns = [
            'throttl',
            'rate exceeded',
            'rate limit',
            'too many requests',
            '429',
            'quota',
            'service unavailable',
            '503',
            'timed out',
            'timeout',
            'temporarily',
        ];

        $needle = \strtolower($error);

        foreach ($patterns as $pattern) {
            if (\str_contains($needle, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Base seconds for exponential backoff between send retries. Isolated so tests can override it to keep the
     * suite instant without touching the production constant.
     */
    protected function retryDelay(): float
    {
        return MESSAGE_SEND_RETRY_DELAY;
    }

    /**
     * Build the provider-specific message for a set of recipients.
     *
     * @param array<string> $to
     * @param array<Attachment> $attachments
     */
    private function buildMessage(
        array $to,
        Document $message,
        Document $provider,
        string $providerType,
        Database $dbForProject,
        array $attachments
    ): Email|SMS|Push {
        $messageData = clone $message;
        $messageData->setAttribute('to', $to);

        $data = match ($providerType) {
            MESSAGE_TYPE_SMS => $this->buildSmsMessage($messageData, $provider),
            MESSAGE_TYPE_PUSH => $this->buildPushMessage($messageData),
            MESSAGE_TYPE_EMAIL => $this->buildEmailMessage($dbForProject, $messageData, $provider, $attachments),
            default => throw new \Exception('Provider with the requested ID is of the incorrect type')
        };

        $data->setOrigin(MESSAGE_SEND_TYPE_EXTERNAL);

        return $data;
    }

    /**
     * Deliver an internal message (OTP or invite) over the requested channel. A missing channel means SMS.
     *
     * @param array<string> $recipients
     * @throws \Exception
     */
    private function sendInternalMessage(
        Document $message,
        Document $project,
        array $recipients,
        UsagePublisher $publisherForUsage,
        ?SMSAdapter $adapterForSMS,
        ?SMSAdapter $adapterForWhatsApp,
        Cache $cache,
        ?string $channel = null,
        bool $fallback = false
    ): void {
        $whatsapp = \in_array($channel, [PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_WHATSAPP_SMS], true);

        if (!$whatsapp && $adapterForSMS === null) {
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

        $countryCode = CallingCode::fromPhoneNumber($recipients[0] ?? '');
        Span::add('message.country_code', $countryCode ?? 'unknown');

        $data = $message->getAttribute('data');

        if (!$whatsapp) {
            $sms = new SMS($recipients, $data['content'], $from);
            $sms->setOrigin(MESSAGE_SEND_TYPE_INTERNAL);

            // Attach the project ID so the provider's delivery logs can be attributed back to the project.
            $sms->setMetadata([MetadataParameter::UUID->value => $project->getId()]);

            $adapterForSMS->send($sms);

            return;
        }

        // WhatsApp authentication templates carry the bare code, never rendered copy.
        $code = $data['code'] ?? $data['content'] ?? null;
        $code = \is_string($code) ? $code : null;

        // Adapter throws and failure results both end up in $errors so either can fall back.
        try {
            if ($adapterForWhatsApp === null) {
                throw new \Exception('WhatsApp adapter is not set.');
            }

            $sms = new SMS($recipients, $code, $from);
            $sms->setOrigin(MESSAGE_SEND_TYPE_INTERNAL);

            // Callback data is the only attribution Meta echoes back.
            $sms->setMetadata([WhatsAppMetadataParameter::CALLBACK_DATA->value => $project->getId() . ':' . $message->getId()]);

            $errors = $this->getSendErrors($adapterForWhatsApp->send($sms));
        } catch (\Throwable $error) {
            $errors = [$error->getMessage()];
        }

        if ($errors === []) {
            // The controller books auth.method.phone for every OTP; this records which of them WhatsApp carried, so Cloud can price the channels apart.
            $usage = new UsageContext();
            $usage->addMetric(METRIC_AUTH_METHOD_PHONE_WHATSAPP, 1);

            if (!empty($countryCode)) {
                $usage->addMetric(\str_replace('{countryCode}', $countryCode, METRIC_AUTH_METHOD_PHONE_WHATSAPP_COUNTRY_CODE), 1);
            }

            $publisherForUsage->enqueue(new Usage(
                project: $project,
                metrics: $usage->getMetrics(),
            ));

            // Meta accepted the message, which is not the same as delivering it: an unreachable
            // recipient is reported minutes later on the status webhook, long after this job is
            // gone. Leave that handler the SMS to send, for as long as the code stays redeemable.
            if ($fallback) {
                $cache->save(
                    PHONE_OTP_WHATSAPP_FALLBACK_KEY . ':' . $project->getId() . ':' . $message->getId(),
                    [
                        'message' => $message->getArrayCopy(),
                        'recipients' => $recipients,
                    ],
                    ttl: TOKEN_EXPIRATION_OTP,
                );
            }

            return;
        }

        $reason = \implode(', ', \array_map(fn (string $error): string => $this->redactPasscode($error, $code), $errors));

        Span::add('message.error', $reason);

        if (!$fallback) {
            // Re-throwing would re-send the WhatsApp message on retry, so only log.
            Console::error('WhatsApp OTP delivery failed for project ' . $project->getId() . ' with no SMS fallback configured: ' . $reason);
            return;
        }

        // The fallback absorbs its own failures for the same reason.
        try {
            if ($adapterForSMS === null) {
                Span::add('message.fallback', 'sms_provider_not_configured');
                Console::error('WhatsApp OTP delivery failed for project ' . $project->getId() . ' and no SMS provider is configured to fall back to: ' . $reason);
                return;
            }

            $sms = new SMS($recipients, $data['content'], $from);
            $sms->setOrigin(MESSAGE_SEND_TYPE_INTERNAL);
            $sms->setMetadata([MetadataParameter::UUID->value => $project->getId()]);

            $fallbackErrors = $this->getSendErrors($adapterForSMS->send($sms));
        } catch (\Throwable $error) {
            $fallbackErrors = [$error->getMessage()];
        }

        if ($fallbackErrors !== []) {
            $fallbackReason = \implode(', ', \array_map(fn (string $error): string => $this->redactPasscode($error, $code), $fallbackErrors));

            Span::add('message.fallback_error', $fallbackReason);
            Console::error('WhatsApp OTP delivery failed for project ' . $project->getId() . ' and the SMS fallback failed too: ' . $reason . ' | fallback: ' . $fallbackReason);
        }
    }

    /**
     * Scrub the passcode out of a provider error before it reaches a span or the log,
     * since a template rejection can quote the rejected parameter.
     */
    private function redactPasscode(string $error, ?string $code): string
    {
        if ($code === null || $code === '') {
            return $error;
        }

        return \str_replace($code, '[redacted]', $error);
    }

    /**
     * Collect the error of every recipient the provider failed to deliver to.
     *
     * @param array<mixed> $response
     * @return array<string>
     */
    private function getSendErrors(array $response): array
    {
        $errors = [];
        $results = $response['results'] ?? [];

        if (!\is_array($results)) {
            return $errors;
        }

        foreach ($results as $result) {
            if (!\is_array($result) || ($result['status'] ?? '') !== 'failure') {
                continue;
            }

            $error = $result['error'] ?? '';
            $errors[] = \is_string($error) && $error !== '' ? $error : 'Unknown error';
        }

        return $errors;
    }


    /**
     * Materialise a message's attachments as files the adapters can read.
     *
     * Uploads are compressed and then encrypted, so a stored file is not what the recipient should get; both
     * are undone here in reverse, as the storage read endpoints do. The plaintext is written under
     * $directory and never back to the file's own path, which on a local install is the stored original.
     *
     * Bytes travel as a path rather than as Attachment content because Sendgrid and Mailgun read only
     * getPath(), and content would otherwise stay resident for the whole fan-out.
     *
     * @return array<Attachment>
     */
    private function prepareAttachments(
        Database $dbForProject,
        Document $message,
        Device $deviceForFiles,
        Document $project,
        string $directory
    ): array {
        $prepared = [];
        $mimes = Config::getParam('storage-mimes');

        foreach ($message->getAttribute('data', [])['attachments'] ?? [] as $attachment) {
            $bucket = $dbForProject->getDocument('buckets', $attachment['bucketId']);
            if ($bucket->isEmpty()) {
                throw new \Exception('Storage bucket with the requested ID could not be found');
            }

            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $attachment['fileId']);
            if ($file->isEmpty()) {
                throw new \Exception('Storage file with the requested ID could not be found');
            }

            $path = $file->getAttribute('path', '');
            if (!$deviceForFiles->exists($path)) {
                throw new \Exception('File not found in ' . $path);
            }

            $contentType = \in_array($file->getAttribute('mimeType'), $mimes)
                ? $file->getAttribute('mimeType')
                : 'text/plain';

            $cipher = $file->getAttribute('openSSLCipher', '');
            $algorithm = $file->getAttribute('algorithm', Compression::NONE);
            $target = $directory . '/' . $bucket->getId() . '/' . $file->getId();

            if (empty($cipher) && !\in_array($algorithm, [Compression::GZIP, Compression::ZSTD], true)) {
                if ($deviceForFiles->getType() !== DeviceType::Local) {
                    $deviceForFiles->copy($path, $target, $this->getLocalDevice($project));
                    $path = $target;
                }

                $prepared[] = new Attachment($file->getAttribute('name'), $path, $contentType);
                continue;
            }

            $source = (string) $deviceForFiles->read($path);

            if (!empty($cipher)) {
                $source = OpenSSL::decrypt(
                    $source,
                    $cipher,
                    System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                    0,
                    \hex2bin($file->getAttribute('openSSLIV')),
                    \hex2bin($file->getAttribute('openSSLTag'))
                );

                // A rotated or missing key leaves ciphertext no one can read, which must fail the send rather
                // than reach a recipient.
                if ($source === false) {
                    throw new \Exception('Failed to decrypt attachment ' . $file->getId());
                }
            }

            $decompressed = match ($algorithm) {
                Compression::ZSTD => (new Zstd())->decompress($source),
                Compression::GZIP => (new GZIP())->decompress($source),
                default => $source,
            };

            // A decompressor reports failure as an empty string. Files stored above the read buffer before 1.5.0
            // recorded an algorithm they were never compressed with, so their bytes are already what the
            // recipient wants; the storage read endpoints fall back to them rather than failing the read.
            $source = $decompressed === '' && (int) $file->getAttribute('sizeOriginal') > 0
                ? $source
                : $decompressed;

            if (!$this->getLocalDevice($project)->write($target, new Stream($source), $contentType)) {
                throw new \Exception('Failed to prepare attachment ' . $file->getId());
            }

            $prepared[] = new Attachment($file->getAttribute('name'), $target, $contentType);
        }

        return $prepared;
    }

    /**
     * @param array<Attachment> $attachments
     */
    private function buildEmailMessage(
        Database $dbForProject,
        Document $message,
        Document $provider,
        array $attachments,
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
        // Not cached: the path is project-scoped and the worker handles
        // messages from many projects (and coroutines run them concurrently).
        return new Local(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
    }


}
