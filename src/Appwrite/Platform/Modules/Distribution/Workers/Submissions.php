<?php

namespace Appwrite\Platform\Modules\Distribution\Workers;

use Appwrite\Distribution\Listings;
use Appwrite\Distribution\Provisioning;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Stores;
use Appwrite\Distribution\Submission\Status;
use Appwrite\Distribution\Submission\Step;
use Appwrite\Distribution\Submissions as DistributionSubmissions;
use Appwrite\Event\Event;
use Appwrite\Event\Message\DistributionSubmission as DistributionSubmissionMessage;
use Appwrite\Event\Message\DistributionSubmissionAction;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\DistributionSubmission as DistributionSubmissionPublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Usage\Distribution as DistributionUsage;
use Appwrite\Utopia\Response\Model\DistributionRelease as ReleaseModel;
use Appwrite\Utopia\Response\Model\DistributionSigningIdentity as SigningIdentityModel;
use Appwrite\Utopia\Response\Model\DistributionSubmission as SubmissionModel;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Distribution\Exception as StoreException;
use Utopia\Distribution\Store;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;

class Submissions extends Action
{
    private const int LOCK_TTL = 180;

    private const float LOCK_TIMEOUT = 10.0;

    public static function getName(): string
    {
        return 'distribution-submissions';
    }

    public function __construct()
    {
        $this
            ->desc('Distribution submissions worker')
            ->groups(['distribution-submissions'])
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('distributionStores')
            ->inject('deviceForDistribution')
            ->inject('publisherForDistributionSubmissions')
            ->inject('queueForEvents')
            ->inject('queueForWebhooks')
            ->inject('publisherForFunctions')
            ->inject('queueForRealtime')
            ->inject('locks')
            ->inject('usage')
            ->inject('publisherForUsage')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Stores $distributionStores,
        Device $deviceForDistribution,
        DistributionSubmissionPublisher $publisherForDistributionSubmissions,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        callable $locks,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
    ): void {
        if ($project->isEmpty()) {
            return;
        }

        $payload = DistributionSubmissionMessage::fromArray($message->getPayload());

        if ($payload->action === DistributionSubmissionAction::Renew) {
            $this->renew($payload->id, $project, $dbForProject, $dbForPlatform, $distributionStores, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $locks);
            return;
        }

        Span::add('submission.id', $payload->id);
        Span::add('submission.action', $payload->action->value);

        $schedules = new Schedules($dbForPlatform);
        $submission = $dbForProject->getDocument('distributionSubmissions', $payload->id);

        if ($submission->isEmpty() || !$this->isActive($submission)) {
            $schedules->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, $payload->id);
            return;
        }

        $release = $this->getReference($dbForProject, 'distributionReleases', $submission, 'release');
        $target = $this->getReference($dbForProject, 'distributionTargets', $submission, 'target');
        $artifact = $this->getReference($dbForProject, 'distributionArtifacts', $submission, 'artifact');
        $connection = $dbForProject->getDocument('distributionConnections', (string) $target->getAttribute('connectionId', ''));

        $submissions = new DistributionSubmissions($dbForProject, $project, $distributionStores, $schedules, $deviceForDistribution, $publisherForDistributionSubmissions);

        $keys = [DistributionSubmissions::lock((string) $project->getSequence(), $submission->getId())];
        if (!$connection->isEmpty()) {
            $keys[] = DistributionSubmissions::storeLock($connection, (string) $target->getAttribute('identifier', ''));
        }

        $emit = function (Document $before, Document $after) use ($project, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $dbForProject, $usage, $publisherForUsage): void {
            if (Listings::isSynced($after) && !Listings::isSynced($before)) {
                $app = $dbForProject->getDocument('distributionApps', (string) $after->getAttribute('appId', ''));

                if (!$app->isEmpty()) {
                    DistributionUsage::publishListingSync($usage, $app, $project, $publisherForUsage);
                }
            }

            if (
                $before->getAttribute('status') === $after->getAttribute('status')
                && (float) $before->getAttribute('rollout', 1) === (float) $after->getAttribute('rollout', 1)
                && $before->getAttribute('errorCode') === $after->getAttribute('errorCode')
            ) {
                return;
            }

            if ($this->isActive($before) && !$this->isActive($after)) {
                $app = $dbForProject->getDocument('distributionApps', (string) $after->getAttribute('appId', ''));
                if (!$app->isEmpty()) {
                    DistributionUsage::publishSubmission($usage, $app, $after, $project, $publisherForUsage);
                }
            }

            $this->publish($queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $project, 'distributionApps.[appId].submissions.[submissionId].update', [
                'appId' => (string) $after->getAttribute('appId'),
                'submissionId' => $after->getId(),
            ], (new SubmissionModel())->payload($after), !$this->isActive($after));
        };

        $run = fn (callable $callback): ?Document => $this->hold($locks, $keys, function () use ($dbForProject, $submissions, $submission, $callback, $emit): ?Document {
            $current = $dbForProject->getDocument('distributionSubmissions', $submission->getId());

            if ($current->isEmpty() || !$this->isActive($current)) {
                return null;
            }

            try {
                $next = $callback($current);
            } catch (\Throwable $error) {
                $next = $submissions->fail($current, $error);
            }

            if ($next !== null) {
                $emit($current, $next);
            }

            return $next;
        });

        $missing = match (true) {
            $release->isEmpty() => Exception::DISTRIBUTION_RELEASE_NOT_FOUND,
            $target->isEmpty() => Exception::DISTRIBUTION_TARGET_NOT_FOUND,
            $artifact->isEmpty() => Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND,
            $connection->isEmpty() => Exception::DISTRIBUTION_CONNECTION_NOT_FOUND,
            default => null,
        };

        try {
            if ($missing !== null) {
                $run(fn (Document $current): Document => $submissions->fail($current, new Exception($missing)));
            } elseif ($payload->action === DistributionSubmissionAction::Control) {
                $this->control($run, $submissions, $payload, $connection);
            } else {
                $this->advance($run, $submissions, $payload, $submission, $release, $target, $artifact, $connection);
            }
        } catch (Contention) {
            Span::add('submission.contended', true);
        }

        $this->publishRelease($dbForProject, $release, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $project);
    }

    /**
     * @param callable(callable(Document): ?Document): ?Document $run
     */
    private function advance(
        callable $run,
        DistributionSubmissions $submissions,
        DistributionSubmissionMessage $payload,
        Document $submission,
        Document $release,
        Document $target,
        Document $artifact,
        Document $connection,
    ): void {
        $released = false;

        while ($this->isActive($submission) && $this->isDue($submission)) {
            $step = Step::tryFrom((string) $submission->getAttribute('step', ''));

            $next = match ($step) {
                null => $this->at($run, $step, fn (Document $current): Document => $submissions->validate($current, $target, $artifact)),
                Step::Validated => $this->at($run, $step, fn (Document $current): Document => $submissions->locate($current, $target, $connection)),
                Step::Located => $this->upload($run, $submissions, $artifact, $connection),
                Step::Uploaded => $this->at($run, $step, fn (Document $current): Document => $submissions->process($current, $connection)),
                Step::Processed => $this->at($run, $step, fn (Document $current): Document => $submissions->release($current, $release, $target, $artifact, $connection)),
                Step::Released, Step::Settled => match (true) {
                    $released => $this->at($run, $step, fn (Document $current): Document => $submissions->settle($current, $connection)),
                    $payload->action === DistributionSubmissionAction::Reconcile => $this->at($run, $step, fn (Document $current): Document => $submissions->reconcile($current, $release, $connection)),
                    default => null,
                },
            };

            if ($next === null || $next->getAttribute('step') === $submission->getAttribute('step')) {
                return;
            }

            $released = $step === Step::Processed;
            $submission = $next;
        }
    }

    /**
     * @param callable(callable(Document): ?Document): ?Document $run
     */
    private function upload(callable $run, DistributionSubmissions $submissions, Document $artifact, Document $connection): ?Document
    {
        $claimed = $this->at($run, Step::Located, fn (Document $current): ?Document => $submissions->claim($current));

        if ($claimed === null) {
            return null;
        }

        $claim = DistributionSubmissions::claimOf($claimed);

        try {
            $result = $submissions->upload($claimed, $artifact, $connection);
        } catch (\Throwable $error) {
            return $run(fn (Document $current): Document => DistributionSubmissions::claimOf($current) === $claim ? $submissions->fail($current, $error) : $current);
        }

        if ($result === null) {
            return null;
        }

        return $this->at($run, Step::Located, fn (Document $current): Document => $submissions->record($current, $claim, $result));
    }

    /**
     * @param callable(callable(Document): ?Document): ?Document $run
     * @param callable(Document): ?Document $callback
     */
    private function at(callable $run, ?Step $step, callable $callback): ?Document
    {
        return $run(fn (Document $current): ?Document => DistributionSubmissions::isAt($current, $step) ? $callback($current) : null);
    }

    /**
     * @param callable(callable(Document): ?Document): ?Document $run
     */
    private function control(callable $run, DistributionSubmissions $submissions, DistributionSubmissionMessage $payload, Document $connection): void
    {
        $status = Status::tryFrom((string) ($payload->data['status'] ?? ''));

        if ($status === null) {
            return;
        }

        $rollout = \is_numeric($payload->data['rollout'] ?? null) ? (float) $payload->data['rollout'] : null;

        $run(fn (Document $current): Document => $submissions->control($current, $connection, $status->value, $rollout));
    }

    /**
     * @param list<string> $keys
     */
    private function hold(callable $locks, array $keys, callable $callback): mixed
    {
        $key = \array_shift($keys);

        if ($key === null) {
            return $callback();
        }

        return $locks($key, self::LOCK_TTL, fn (): mixed => $this->hold($locks, $keys, $callback), self::LOCK_TIMEOUT);
    }

    private function getReference(Database $dbForProject, string $collection, Document $submission, string $name): Document
    {
        $document = $dbForProject->getDocument($collection, (string) $submission->getAttribute("{$name}Id", ''));

        if ($document->isEmpty() || (string) $document->getSequence() !== (string) $submission->getAttribute("{$name}InternalId", '')) {
            return new Document();
        }

        return $document;
    }

    private function publishRelease(
        Database $dbForProject,
        Document $release,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Document $project,
    ): void {
        if ($release->isEmpty()) {
            return;
        }

        $current = $dbForProject->getDocument('distributionReleases', $release->getId());

        if ($current->isEmpty() || $current->getAttribute('status') === $release->getAttribute('status')) {
            return;
        }

        $this->publish($queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $project, 'distributionApps.[appId].releases.[releaseId].update', [
            'appId' => (string) $current->getAttribute('appId'),
            'releaseId' => $current->getId(),
        ], (new ReleaseModel())->payload($current), true);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $payload
     */
    private function publish(
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        Document $project,
        string $event,
        array $params,
        array $payload,
        bool $final,
    ): void {
        $queueForEvents
            ->reset()
            ->setProject($project)
            ->setEvent($event)
            ->setPayload($payload);

        foreach ($params as $key => $value) {
            $queueForEvents->setParam($key, $value);
        }

        $queueForRealtime
            ->reset()
            ->setSubscribers(['console'])
            ->from($queueForEvents)
            ->trigger();

        if (!$final) {
            return;
        }

        $queueForWebhooks->from($queueForEvents)->trigger();

        $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
            event: $queueForEvents->getEvent(),
            params: $queueForEvents->getParams(),
            project: $queueForEvents->getProject(),
            payload: $queueForEvents->getPayload(),
        ));
    }

    private function isActive(Document $submission): bool
    {
        return DistributionSubmissions::isActive((string) $submission->getAttribute('status', ''), (float) $submission->getAttribute('rollout', 1));
    }

    private function isDue(Document $submission): bool
    {
        $retryAt = $submission->getAttribute('retryAt');

        return empty($retryAt) || new \DateTimeImmutable($retryAt) <= new \DateTimeImmutable();
    }

    /**
     * Keeps a signing identity's status in step with its expiry and, for a
     * managed iOS identity, regenerates the profiles about to expire. A
     * failed renewal is recorded and retried as `Provisioning::interval()`
     * backs off.
     */
    private function renew(
        string $signingIdentityId,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Stores $distributionStores,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        callable $locks,
    ): void {
        Span::add('signing_identity.id', $signingIdentityId);

        $schedules = new Schedules($dbForPlatform);
        $provisioning = new Provisioning($dbForProject);
        $identity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);
        $now = new \DateTimeImmutable();
        $current = $identity;

        if (!$identity->isEmpty() && Provisioning::interval($identity, $now) === Provisioning::RENEWING_MINUTES) {
            $current = $this->provision($provisioning, $identity, $dbForProject, $distributionStores, $locks);
        }

        if ($current->isEmpty()) {
            $schedules->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SIGNING_IDENTITY, $signingIdentityId);
            return;
        }

        $current = $provisioning->track($current, $now);

        if ($current->getAttribute('status') !== $identity->getAttribute('status') || $current->getAttribute('expiresAt') !== $identity->getAttribute('expiresAt')) {
            $this->publish($queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $project, 'distributionSigningIdentities.[signingIdentityId].update', [
                'signingIdentityId' => $current->getId(),
            ], (new SigningIdentityModel())->payload($current), true);
        }

        $minutes = Provisioning::interval($current, $now);

        if ($minutes === 0) {
            $schedules->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SIGNING_IDENTITY, $signingIdentityId);
            return;
        }

        $schedules->write($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SIGNING_IDENTITY, $current, $minutes);
    }

    /**
     * Renews the identity's expiring profiles under its team's lock, against
     * the identity as it stands once the lock is held: a rotation may have
     * replaced its profiles meanwhile.
     */
    private function provision(Provisioning $provisioning, Document $identity, Database $dbForProject, Stores $distributionStores, callable $locks): Document
    {
        try {
            return $locks(Provisioning::lock((string) $identity->getAttribute('teamId', '')), self::LOCK_TTL, function (Distributed $lock) use ($provisioning, $identity, $dbForProject, $distributionStores): Document {
                $current = $dbForProject->getDocument('distributionSigningIdentities', $identity->getId());

                if ($current->isEmpty() || Provisioning::interval($current, new \DateTimeImmutable()) !== Provisioning::RENEWING_MINUTES) {
                    return $current;
                }

                try {
                    return $provisioning->renew($this->store($current, $dbForProject, $distributionStores), $lock, $current);
                } catch (Exception $error) {
                    Span::add('signing_identity.error', $error->getMessage());

                    return $provisioning->fail($lock, $current, $error->getType());
                } catch (StoreException $error) {
                    Span::add('signing_identity.error', $error->getMessage());

                    return $provisioning->fail($lock, $current, Provisioning::failure($error)->getType());
                }
            }, self::LOCK_TIMEOUT);
        } catch (Contention) {
            Span::add('signing_identity.contended', true);
        }

        return $dbForProject->getDocument('distributionSigningIdentities', $identity->getId());
    }

    /**
     * The Apple store of the identity's connection, which must be the one
     * the identity was created with.
     */
    private function store(Document $identity, Database $dbForProject, Stores $distributionStores): Store\Apple
    {
        $connection = $dbForProject->getDocument('distributionConnections', (string) $identity->getAttribute('connectionId', ''));

        if ($connection->isEmpty() || (string) $connection->getSequence() !== (string) $identity->getAttribute('connectionInternalId', '')) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $store = $distributionStores->connection($connection);

        if (!$store instanceof Store\Apple) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, 'A managed iOS signing identity needs an App Store Connect connection.');
        }

        return $store;
    }
}
