<?php

namespace Appwrite\Platform\Modules\Distribution\Workers;

use Appwrite\Distribution\Artifact\Status;
use Appwrite\Distribution\Artifact\Type;
use Appwrite\Distribution\Builds as DistributionBuilds;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Signing;
use Appwrite\Event\Event;
use Appwrite\Event\Message\DistributionBuild as DistributionBuildMessage;
use Appwrite\Event\Message\DistributionBuildAction;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response\Model\DistributionArtifact;
use OpenRuntimes\Orchestrator\Callback\JobArtifact;
use OpenRuntimes\Orchestrator\Callback\JobExit;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Enum\JobState;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Exception\ClientException;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\JobStatus;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;

class Builds extends Action
{
    /**
     * Outlives the events route's 900 s staleness window, so no replay it accepts is applied twice.
     */
    private const int DEDUPE_TTL = 3600;

    /**
     * Outlives the longest single call a holder makes and cannot refresh around:
     * moving a signed bundle of up to the 4 GB upload limit.
     */
    private const int LOCK_TTL = DistributionBuilds::TRANSFER_TIMEOUT;
    private const float LOCK_TIMEOUT = 10.0;

    /**
     * A job's create can outlive the client timeout, so it can start this long after dispatch.
     */
    private const int GRACE = 120;

    private const string TIMEOUT_REASON = 'timeout';
    private const string DEADLINE_EXCEEDED = 'DeadlineExceeded';

    private const string EVENT = 'distributionApps.[appId].artifacts.[artifactId].update';

    private const string RESULT = 'result';
    private const string SIGNING = 'signing';

    private const array MESSAGES = [
        DistributionBuilds::ERROR_ARTIFACT_MALFORMED => 'The file is not a readable bundle, or exceeds an inspection limit.',
        DistributionBuilds::ERROR_ARTIFACT_INVALID => 'The signature, provisioning profile or team of the file is invalid.',
        DistributionBuilds::ERROR_ARTIFACT_SIZE_MISMATCH => 'The stored file does not match the uploaded size. Upload it again.',
        DistributionBuilds::ERROR_ARTIFACT_IDENTIFIER_UNKNOWN => 'No target of this app matches the identifier of the file. Create a target for it, then upload the file again.',
        DistributionBuilds::ERROR_ARTIFACT_UNSIGNED => 'The IPA is not signed. Sign it with a distribution certificate and provisioning profile, then upload it again.',
        DistributionBuilds::ERROR_SIGNING_INPUT_INVALID => 'The bundle could not be read, or its package name or version code does not match the target.',
        DistributionBuilds::ERROR_ARTIFACT_ALREADY_SIGNED => 'The bundle is already signed.',
        DistributionBuilds::ERROR_SIGNING_FAILED => 'Signing failed. Check the passwords and key alias of the signing identity.',
        DistributionBuilds::ERROR_SIGNING_VERIFICATION_FAILED => 'The signed bundle did not verify.',
        DistributionBuilds::ERROR_SIGNING_BUNDLE_INVALID => 'The signing job could not load the signing identity.',
        DistributionBuilds::ERROR_JOB_TIMEOUT => 'The job ran longer than its time limit.',
        DistributionBuilds::ERROR_JOB_FAILED => 'The job failed. Upload the file again.',
        DistributionBuilds::ERROR_JOB_LOST => 'The job stopped reporting progress. Upload the file again.',
    ];

    public static function getName(): string
    {
        return 'distribution-builds';
    }

    public function __construct()
    {
        $this
            ->desc('Distribution builds worker')
            ->groups(['distribution-builds'])
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('distributionBuilds')
            ->inject('jobsForDistributionSigning')
            ->inject('deviceForDistribution')
            ->inject('queueForRealtime')
            ->inject('queueForEvents')
            ->inject('queueForWebhooks')
            ->inject('publisherForFunctions')
            ->inject('cache')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Jobs $jobsForDistributionSigning,
        Device $deviceForDistribution,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Cache $cache,
        callable $locks,
    ): void {
        $payload = DistributionBuildMessage::fromArray($message->getPayload());
        Span::add('distribution.action', $payload->action->value);

        if ($project->isEmpty()) {
            return;
        }

        $schedules = new Schedules($dbForPlatform);
        $signing = new Signing($cache);

        switch ($payload->action) {
            case DistributionBuildAction::Inspect:
                $this->inspect($project, $dbForProject, $distributionBuilds, $locks, $payload->id);
                break;
            case DistributionBuildAction::Event:
                $this->event($payload, $project, $dbForProject, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $cache, $locks);
                break;
            case DistributionBuildAction::Reconcile:
                $this->reconcile($payload->id, $project, $dbForProject, $distributionBuilds, $jobsForDistributionSigning, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $locks);
                break;
            case DistributionBuildAction::Dispatch:
                break;
        }
    }

    /**
     * Held under the artifact lock so callbacks of a fast job wait until the
     * job is recorded on the artifact, instead of being dropped as stale.
     */
    private function inspect(Document $project, Database $dbForProject, DistributionBuilds $distributionBuilds, callable $locks, string $artifactId): void
    {
        Span::add('artifact.id', $artifactId);

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $artifactId), self::LOCK_TTL, function () use ($dbForProject, $distributionBuilds, $artifactId): void {
            $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);
            if ($artifact->isEmpty()
                || $artifact->getAttribute('status') !== Status::Processing->value
                || ($this->job($artifact)['id'] ?? '') !== '') {
                return;
            }

            $distributionBuilds->inspect($artifact);
        }, self::LOCK_TIMEOUT);
    }

    private function event(
        DistributionBuildMessage $payload,
        Document $project,
        Database $dbForProject,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Cache $cache,
        callable $locks,
    ): void {
        $meta = $payload->data['meta'] ?? [];
        $artifactId = (string) ($meta['artifactId'] ?? '');
        $callback = CallbackEvent::tryFrom($payload->event);
        if ($artifactId === '' || $callback === null || $callback === CallbackEvent::Log) {
            return;
        }

        Span::add('artifact.id', $artifactId);
        Span::add('job.stage', (string) ($meta['stage'] ?? ''));

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $artifactId), self::LOCK_TTL, function () use ($payload, $meta, $artifactId, $callback, $project, $dbForProject, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $cache): void {
            $key = 'distribution-event-' . $project->getSequence() . '-' . $payload->id;
            if ($payload->id !== '' && $cache->load($key, self::DEDUPE_TTL) !== false) {
                return;
            }

            $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);
            if ($artifact->isEmpty() || $artifact->getAttribute('status') !== Status::Processing->value) {
                return;
            }

            $job = $this->job($artifact);
            $jobId = (string) ($payload->data['jobId'] ?? '');
            if (($meta['stage'] ?? '') !== ($job['stage'] ?? '')
                || (string) ($meta['attempt'] ?? '') !== (string) ($job['attempt'] ?? 1)
                || ($jobId !== '' && $jobId !== ($job['id'] ?? ''))) {
                return;
            }

            try {
                $event = match ($callback) {
                    CallbackEvent::Artifact => JobArtifact::fromArray($payload->data),
                    CallbackEvent::Exit => JobExit::fromArray($payload->data),
                    default => null,
                };
            } catch (ClientException) {
                return;
            }

            match (true) {
                $callback === CallbackEvent::Start => $this->onStart($dbForProject, $project, $artifact, $signing, $deviceForDistribution),
                $event instanceof JobArtifact => $this->onArtifact($dbForProject, $project, $artifact, $event, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions),
                $event instanceof JobExit => $this->onExit($dbForProject, $project, $artifact, $event, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions),
                $callback === CallbackEvent::Complete => $this->onComplete($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions),
                default => $artifact,
            };

            if ($payload->id !== '' && $cache->save($key, true, ttl: self::DEDUPE_TTL) === false) {
                throw new \RuntimeException('Failed to record processed distribution callback.');
            }
        }, self::LOCK_TIMEOUT);
    }

    private function reconcile(
        string $id,
        Document $project,
        Database $dbForProject,
        DistributionBuilds $distributionBuilds,
        Jobs $jobsForDistributionSigning,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        callable $locks,
    ): void {
        if (!$dbForProject->getDocument('distributionBuilds', $id)->isEmpty()) {
            return;
        }

        Span::add('artifact.id', $id);

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $id), self::LOCK_TTL, function () use ($id, $project, $dbForProject, $distributionBuilds, $jobsForDistributionSigning, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions): void {
            $artifact = $dbForProject->getDocument('distributionArtifacts', $id);
            if ($artifact->isEmpty() || $artifact->getAttribute('status') !== Status::Processing->value) {
                $this->revoke($project, $artifact, $signing, $deviceForDistribution);
                $schedules->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $id);
                return;
            }

            $job = $this->job($artifact);
            $jobId = (string) ($job['id'] ?? '');
            if ($jobId === '') {
                $distributionBuilds->inspect($artifact);
                return;
            }

            try {
                $status = $jobsForDistributionSigning->get($jobId);
            } catch (ApiException $error) {
                if ($error->statusCode !== 404) {
                    throw $error;
                }
                $status = null;
            }
            Span::add('job.state', $status?->status->value ?? 'missing');

            $artifact = $this->recover($dbForProject, $artifact, $status, $deviceForDistribution);
            $join = $this->join($artifact);
            if (($join['failed'] ?? false) === true && ($join['exitCode'] ?? null) === 0) {
                $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
                return;
            }

            if (\is_array($join[self::RESULT] ?? null) && ($join['exitCode'] ?? null) === 0 && ($join['completed'] ?? false) === true) {
                $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
                return;
            }

            if ($status === null) {
                if ($this->expired($artifact)) {
                    $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_LOST, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
                }
                return;
            }

            if ($status->status === JobState::Failed || $status->status === JobState::Cancelled) {
                $this->finalize($dbForProject, $project, $artifact, false, $this->failure($artifact, $status), $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
                return;
            }

            if ($this->expired($artifact)) {
                $code = $status->status === JobState::Completed ? DistributionBuilds::ERROR_JOB_FAILED : DistributionBuilds::ERROR_JOB_TIMEOUT;
                $distributionBuilds->cancel($artifact);
                $this->finalize($dbForProject, $project, $artifact, false, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
            }
        }, self::LOCK_TIMEOUT);
    }

    /**
     * `Completed` can arrive while post-job uploads still run, so a sign job
     * has finished only once its signed bundle is stored.
     */
    private function recover(Database $dbForProject, Document $artifact, ?JobStatus $status, Device $deviceForDistribution): Document
    {
        $join = $this->join($artifact);
        $finished = $status?->status === JobState::Completed || ($status === null && ($join['exitCode'] ?? null) === 0);
        if (!$finished) {
            return $artifact;
        }

        $changes = ['exitCode' => 0, 'completed' => true];
        if (($this->job($artifact)['stage'] ?? '') === DistributionBuilds::STAGE_SIGN) {
            if (!$this->stored($artifact, $deviceForDistribution)) {
                return $artifact;
            }

            $copy = 'signed/' . $artifact->getId() . '/result.json';
            if (!\is_array($join[self::RESULT] ?? null) && $deviceForDistribution->exists($deviceForDistribution->getPath($copy))) {
                $result = \json_decode((string) $deviceForDistribution->read($deviceForDistribution->getPath($copy)), true);
                if (\is_array($result)) {
                    $changes[self::RESULT] = $result;
                }
            }
        }

        return $this->record($dbForProject, $artifact, $changes);
    }

    /**
     * A moved bundle has left its output path, but the row took its signed fields first.
     */
    private function stored(Document $artifact, Device $deviceForDistribution): bool
    {
        return $artifact->getAttribute('signed') === true
            || $deviceForDistribution->exists($deviceForDistribution->getPath('signed/' . $artifact->getId() . '/app.aab'));
    }

    private function failure(Document $artifact, JobStatus $status): string
    {
        $code = DistributionBuilds::error((string) ($this->job($artifact)['stage'] ?? ''), $status->exitCode ?? 0) ?? DistributionBuilds::ERROR_JOB_FAILED;
        if ($code !== DistributionBuilds::ERROR_JOB_FAILED) {
            return $code;
        }

        $deadline = \str_contains($status->error ?? '', self::DEADLINE_EXCEEDED) || $this->elapsed($this->since($artifact), DistributionBuilds::TIMEOUT);

        return $deadline ? DistributionBuilds::ERROR_JOB_TIMEOUT : DistributionBuilds::ERROR_JOB_FAILED;
    }

    protected function onStart(Database $dbForProject, Document $project, Document $artifact, Signing $signing, Device $deviceForDistribution): Document
    {
        $this->revoke($project, $artifact, $signing, $deviceForDistribution);

        if (($this->join($artifact)['startedAt'] ?? '') !== '') {
            return $artifact;
        }

        return $this->record($dbForProject, $artifact, ['startedAt' => DateTime::now()]);
    }

    /**
     * `result` is held for the join. Every other failed artifact fails the job
     * at once: the signing bundle or the input never arrived, or the signed
     * bundle was not delivered. A failed `result` waits for the exit, whose
     * code says why.
     */
    protected function onArtifact(
        Database $dbForProject,
        Document $project,
        Document $artifact,
        JobArtifact $event,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): Document {
        if ($event->artifactId === self::SIGNING) {
            $this->revoke($project, $artifact, $signing, $deviceForDistribution);
        }

        $failed = $event->status === 'failed';
        if (!$failed && $event->status !== 'success') {
            return $artifact;
        }

        if ($event->artifactId !== self::RESULT) {
            if (!$failed) {
                return $artifact;
            }

            Span::add('job.artifact.id', $event->artifactId);
            Span::add('job.artifact.error', $event->error?->code->value ?? '');

            return $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
        }

        if ($failed || !\is_array($event->content)) {
            $artifact = $this->record($dbForProject, $artifact, ['failed' => true]);

            if (($this->join($artifact)['exitCode'] ?? null) === 0) {
                return $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
            }

            return $artifact;
        }

        $artifact = $this->record($dbForProject, $artifact, [self::RESULT => $event->content]);

        return $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
    }

    /**
     * A non-zero exit is final: no complete event follows a job that failed
     * before it ran, and the code names the reason the job refused the file.
     */
    protected function onExit(
        Database $dbForProject,
        Document $project,
        Document $artifact,
        JobExit $exit,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): Document {
        $this->revoke($project, $artifact, $signing, $deviceForDistribution);

        $artifact = $this->record($dbForProject, $artifact, ['exitCode' => $exit->exitCode]);
        Span::add('job.exit', $exit->exitCode);

        if ($exit->exitCode !== 0) {
            $code = $exit->reason === self::TIMEOUT_REASON
                ? DistributionBuilds::ERROR_JOB_TIMEOUT
                : (DistributionBuilds::error((string) ($this->job($artifact)['stage'] ?? ''), $exit->exitCode) ?? DistributionBuilds::ERROR_JOB_FAILED);

            return $this->finalize($dbForProject, $project, $artifact, false, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
        }

        if (($this->join($artifact)['failed'] ?? false) === true) {
            return $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
        }

        return $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
    }

    protected function onComplete(
        Database $dbForProject,
        Document $project,
        Document $artifact,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): Document {
        $artifact = $this->record($dbForProject, $artifact, ['completed' => true]);

        return $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
    }

    /**
     * The join: a clean exit, the complete event and the result. Called again
     * by every callback and by reconcile, so each step is idempotent.
     */
    protected function ready(
        Database $dbForProject,
        Document $project,
        Document $artifact,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): Document {
        if ($artifact->getAttribute('status') !== Status::Processing->value) {
            return $artifact;
        }

        $join = $this->join($artifact);
        $result = $join[self::RESULT] ?? null;
        if (($join['exitCode'] ?? null) !== 0 || ($join['completed'] ?? false) !== true || !\is_array($result)) {
            return $artifact;
        }

        return match ($this->job($artifact)['stage'] ?? '') {
            DistributionBuilds::STAGE_INSPECT => $this->inspected($dbForProject, $project, $artifact, $result, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions),
            DistributionBuilds::STAGE_SIGN => $this->signed($dbForProject, $project, $artifact, $result, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions),
            default => $artifact,
        };
    }

    /**
     * @param array<string, mixed> $result
     */
    private function inspected(
        Database $dbForProject,
        Document $project,
        Document $artifact,
        array $result,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): Document {
        $finalize = fn (Document $artifact, bool $success, string $code = ''): Document => $this->finalize($dbForProject, $project, $artifact, $success, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);

        if ((int) ($result['size'] ?? -1) !== (int) $artifact->getAttribute('size', 0)) {
            return $finalize($artifact, false, DistributionBuilds::ERROR_ARTIFACT_SIZE_MISMATCH);
        }

        $type = Type::tryFrom((string) $artifact->getAttribute('type', ''));
        $platform = $type === Type::Ipa ? Platform::Ios : Platform::Android;
        $identifier = \is_string($result['identifier'] ?? null) ? $result['identifier'] : '';
        $version = $result['versionCode'] ?? null;

        $target = $identifier === '' ? new Document() : $dbForProject->findOne('distributionTargets', [
            Query::equal('appInternalId', [$artifact->getAttribute('appInternalId')]),
            Query::equal('platform', [$platform->value]),
            Query::equal('identifier', [$identifier]),
        ]);

        try {
            $artifact = $this->apply($dbForProject, $artifact, [
                'platform' => $platform->value,
                'targetId' => $target->getId(),
                'targetInternalId' => $target->getSequence() ?? '',
                'digest' => (string) ($result['digest'] ?? ''),
                'identifier' => $identifier,
                'versionName' => (string) ($result['versionName'] ?? ''),
                'versionCode' => $type === Type::Aab && \is_int($version) ? $version : null,
                'buildNumber' => $type === Type::Ipa && \is_scalar($version) ? (string) $version : '',
                'signed' => ($result['signed'] ?? false) === true,
                'fingerprint' => (string) ($result['fingerprint'] ?? ''),
                'manifest' => [
                    'minSdk' => $result['minSdk'] ?? null,
                    'targetSdk' => $result['targetSdk'] ?? null,
                    'entitlements' => $result['entitlements'] ?? null,
                    'profileExpiresAt' => $result['profileExpiresAt'] ?? null,
                    'teamId' => $result['teamId'] ?? null,
                ],
            ]);
        } catch (Structure) {
            return $finalize($artifact, false, DistributionBuilds::ERROR_ARTIFACT_MALFORMED);
        }

        if ($target->isEmpty()) {
            return $finalize($artifact, false, DistributionBuilds::ERROR_ARTIFACT_IDENTIFIER_UNKNOWN);
        }

        if ($artifact->getAttribute('signed') === true) {
            return $finalize($artifact, true);
        }

        if ($type === Type::Ipa) {
            return $finalize($artifact, false, DistributionBuilds::ERROR_ARTIFACT_UNSIGNED);
        }

        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', (string) $target->getAttribute('signingIdentityId', ''));
        if ($signingIdentity->isEmpty() || $signingIdentity->getAttribute('platform') !== Platform::Android->value) {
            return $finalize($artifact, true);
        }

        $artifact = $distributionBuilds->sign($artifact, $target, $signingIdentity);
        $this->broadcast($queueForRealtime, $project, $artifact);

        return $artifact;
    }

    /**
     * The row takes the signed fields before the bundle moves: a retry recognises a moved bundle by its digest.
     *
     * @param array<string, mixed> $result
     */
    private function signed(
        Database $dbForProject,
        Document $project,
        Document $artifact,
        array $result,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): Document {
        $finalize = fn (Document $artifact, bool $success, string $code = ''): Document => $this->finalize($dbForProject, $project, $artifact, $success, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);

        $digest = (string) ($result['digest'] ?? '');
        $source = $deviceForDistribution->getPath('signed/' . $artifact->getId() . '/app.aab');
        $path = (string) $artifact->getAttribute('path', '');
        $stored = $deviceForDistribution->exists($source);
        $moved = !$stored && $digest !== '' && $artifact->getAttribute('signed') === true && \hash_equals((string) $artifact->getAttribute('digest', ''), $digest);

        if (!$stored && !$moved) {
            return $finalize($artifact, false, DistributionBuilds::ERROR_JOB_FAILED);
        }

        if ($stored) {
            try {
                $artifact = $this->apply($dbForProject, $artifact, [
                    'digest' => $digest,
                    'fingerprint' => (string) ($result['fingerprint'] ?? ''),
                    'signed' => true,
                    'size' => $deviceForDistribution->getFileSize($source),
                    'manifest' => [
                        'minSdk' => $result['minSdk'] ?? null,
                        'targetSdk' => $result['targetSdk'] ?? null,
                        'expiresAt' => $result['expiresAt'] ?? null,
                    ],
                ]);
            } catch (Structure) {
                return $finalize($artifact, false, DistributionBuilds::ERROR_SIGNING_VERIFICATION_FAILED);
            }

            if (!$deviceForDistribution->move($source, $path)) {
                throw new \RuntimeException("Failed to store the signed bundle of artifact {$artifact->getId()}.");
            }
        }

        $this->backfill($dbForProject, $artifact, $result);

        return $finalize($artifact, true);
    }

    /**
     * A JKS identity is stored without the fingerprint and expiry the server
     * cannot read itself; the first sign job reports them.
     *
     * @param array<string, mixed> $result
     */
    private function backfill(Database $dbForProject, Document $artifact, array $result): void
    {
        $target = $dbForProject->getDocument('distributionTargets', (string) $artifact->getAttribute('targetId', ''));
        $signingIdentityId = (string) ($this->job($artifact)['signingIdentityId'] ?? $target->getAttribute('signingIdentityId', ''));
        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);
        $fingerprint = (string) ($result['fingerprint'] ?? '');
        if ($signingIdentity->isEmpty() || $signingIdentity->getAttribute('fingerprint', '') !== '' || $fingerprint === '') {
            return;
        }

        $update = ['fingerprint' => $fingerprint];
        $expiresAt = \DateTime::createFromFormat(\DateTimeInterface::ATOM, (string) ($result['expiresAt'] ?? ''));
        if ($expiresAt !== false) {
            $update['expiresAt'] = DateTime::format($expiresAt);
        }

        $dbForProject->updateDocument('distributionSigningIdentities', $signingIdentity->getId(), new Document($update));
    }

    protected function finalize(
        Database $dbForProject,
        Document $project,
        Document $artifact,
        bool $success,
        string $code,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): Document {
        if ($artifact->isEmpty()) {
            return $artifact;
        }

        $applied = $dbForProject->updateDocuments('distributionArtifacts', new Document([
            'status' => $success ? Status::Ready->value : Status::Invalid->value,
            'errorCode' => $code,
            'errorMessage' => self::MESSAGES[$code] ?? '',
        ]), [
            Query::equal('$id', [$artifact->getId()]),
            Query::equal('status', [Status::Processing->value]),
        ]);

        if (($this->job($artifact)['stage'] ?? '') === DistributionBuilds::STAGE_SIGN) {
            $this->revoke($project, $artifact, $signing, $deviceForDistribution);
            $deviceForDistribution->deletePath('signed/' . $artifact->getId());
        }

        $schedules->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $artifact->getId());

        $artifact = $dbForProject->getDocument('distributionArtifacts', $artifact->getId());
        Span::add('artifact.status', (string) $artifact->getAttribute('status', ''));
        if ($code !== '') {
            Span::add('artifact.error', $code);
        }

        if ($applied > 0 && !$artifact->isEmpty()) {
            $this->dispatchUpdate($queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $project, $artifact);
        }

        return $artifact;
    }

    /**
     * Console realtime, webhooks and event-triggered functions for a terminal
     * transition, as `Functions\Workers\Jobs::dispatchUpdate()` does.
     */
    protected function dispatchUpdate(
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Document $project,
        Document $artifact,
    ): void {
        $update = $queueForEvents
            ->setProject($project)
            ->setEvent(self::EVENT)
            ->setParam('appId', $artifact->getAttribute('appId'))
            ->setParam('artifactId', $artifact->getId())
            ->setPayload((new DistributionArtifact())->payload($artifact));

        $queueForWebhooks->from($update)->trigger();

        $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
            event: $update->getEvent(),
            params: $update->getParams(),
            project: $update->getProject(),
            user: $update->getUser(),
            userId: $update->getUserId(),
            payload: $update->getPayload(),
            platform: $update->getPlatform(),
        ));

        $this->broadcast($queueForRealtime, $project, $artifact);
    }

    private function broadcast(Realtime $queueForRealtime, Document $project, Document $artifact): void
    {
        $queueForRealtime
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent(self::EVENT)
            ->setParam('appId', $artifact->getAttribute('appId'))
            ->setParam('artifactId', $artifact->getId())
            ->setPayload((new DistributionArtifact())->payload($artifact))
            ->trigger();
    }

    protected function expired(Document $artifact): bool
    {
        return $this->elapsed($this->since($artifact), DistributionBuilds::TIMEOUT + DistributionBuilds::POST_BUDGET + self::GRACE);
    }

    private function since(Document $artifact): string
    {
        return (string) ($this->join($artifact)['startedAt'] ?? '')
            ?: (string) ($this->job($artifact)['dispatchedAt'] ?? '')
            ?: (string) $artifact->getUpdatedAt();
    }

    private function elapsed(string $since, int $seconds): bool
    {
        try {
            $from = new \DateTimeImmutable($since);
        } catch (\Exception) {
            return true;
        }

        return $from->getTimestamp() + $seconds <= \time();
    }

    /**
     * The bundle object is a storage grant that never expires by itself, so it
     * is deleted on every callback that proves the job no longer needs it.
     */
    private function revoke(Document $project, Document $artifact, Signing $signing, Device $deviceForDistribution): void
    {
        $job = $this->job($artifact);
        if (($job['stage'] ?? '') !== DistributionBuilds::STAGE_SIGN) {
            return;
        }

        $signing->revoke($project, $artifact->getId(), (int) ($job['attempt'] ?? 1));

        $bundle = (string) ($job['bundle'] ?? '');
        if ($bundle !== '') {
            $deviceForDistribution->delete($deviceForDistribution->getPath($bundle));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function job(Document $artifact): array
    {
        $job = $artifact->getAttribute('metadata', [])['job'] ?? [];

        return \is_array($job) ? $job : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function join(Document $artifact): array
    {
        $job = $this->job($artifact);
        $join = $job['join'] ?? [];

        return \is_array($join) && ($join['id'] ?? null) === ($job['id'] ?? '') ? $join : [];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function record(Database $dbForProject, Document $artifact, array $changes): Document
    {
        $metadata = $artifact->getAttribute('metadata', []);
        $job = $this->job($artifact);
        $job['join'] = \array_merge($this->join($artifact), $changes, ['id' => $job['id'] ?? '']);
        $metadata['job'] = $job;

        return $this->apply($dbForProject, $artifact, ['metadata' => $metadata]);
    }

    /**
     * @param array<string, mixed> $update
     */
    private function apply(Database $dbForProject, Document $artifact, array $update): Document
    {
        $dbForProject->updateDocuments('distributionArtifacts', new Document($update), [
            Query::equal('$id', [$artifact->getId()]),
            Query::equal('status', [Status::Processing->value]),
        ]);

        return $dbForProject->getDocument('distributionArtifacts', $artifact->getId());
    }
}
