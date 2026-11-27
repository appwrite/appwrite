<?php

namespace Appwrite\Platform\Modules\Distribution\Workers;

use Appwrite\Distribution\Artifact\Source;
use Appwrite\Distribution\Artifact\Status;
use Appwrite\Distribution\Artifact\Type;
use Appwrite\Distribution\Build\Result;
use Appwrite\Distribution\Build\Runner;
use Appwrite\Distribution\Build\Stage;
use Appwrite\Distribution\Build\Status as BuildStatus;
use Appwrite\Distribution\Builds as DistributionBuilds;
use Appwrite\Distribution\Logs;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Signing;
use Appwrite\Event\Event;
use Appwrite\Event\Message\DistributionBuild as DistributionBuildMessage;
use Appwrite\Event\Message\DistributionBuildAction;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Action;
use Appwrite\Usage\Context;
use Appwrite\Usage\Distribution as DistributionUsage;
use Appwrite\Utopia\Response\Model\DistributionArtifact;
use Appwrite\Utopia\Response\Model\DistributionBuild;
use Appwrite\Utopia\Response\Model\DistributionLog;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use OpenRuntimes\Orchestrator\Callback\JobArtifact;
use OpenRuntimes\Orchestrator\Callback\JobExit;
use OpenRuntimes\Orchestrator\Callback\JobLog;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Enum\ErrorCode;
use OpenRuntimes\Orchestrator\Enum\JobState;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Exception\ClientException;
use OpenRuntimes\Orchestrator\Jobs;
use OpenRuntimes\Orchestrator\Model\JobStatus;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

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

    protected const string EVENT = 'distributionApps.[appId].artifacts.[artifactId].update';
    private const string ARTIFACT_CREATE = 'distributionApps.[appId].artifacts.[artifactId].create';
    private const string BUILD_EVENT = 'distributionApps.[appId].builds.[buildId].update';
    private const string LOG_EVENT = 'distributionApps.[appId].builds.[buildId].logs.[logId].create';

    private const string RESULT = 'result';
    private const string SIGNING = 'signing';
    private const string SOURCE = 'source';
    private const string EXTRACT = 'extract';
    private const string CACHE_PULL = 'cachePull';
    private const string OUTPUT = 'output';
    private const string RESULT_COPY = 'resultCopy';

    /**
     * The post-job uploads a build's join needs.
     */
    private const array OUTPUTS = [self::OUTPUT, self::RESULT, self::RESULT_COPY];

    private const string ARTIFACT_SUCCESS = 'success';
    private const string ARTIFACT_FAILED = 'failed';

    /**
     * Exit reasons of a `-1` exit whose pre-job artifact failed, on Kubernetes and on Docker.
     */
    private const array PRE_JOB_FAILURES = ['init container artifact-pre failed', 'sidecar exited before inputs completed'];

    private const string LOGS_LIMIT = '50000000';

    private const int SECRETS_TTL = 60;
    private const int SECRETS_LIMIT = 64;

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

    /**
     * Masking secrets per build attempt, so a burst of log events decrypts them once.
     *
     * @var array<string, array{expiresAt: int, values: list<string>}>
     */
    private array $secrets = [];

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
            ->inject('usage')
            ->inject('publisherForUsage')
            ->inject('jobsForDistribution')
            ->inject('vcsFactory')
            ->inject('installationTokens')
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
        Context $usage,
        UsagePublisher $publisherForUsage,
        Jobs $jobsForDistribution,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
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
                $this->event($payload, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $cache, $locks, $usage, $publisherForUsage);
                break;
            case DistributionBuildAction::Reconcile:
                $this->reconcile($payload->id, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $jobsForDistributionSigning, $jobsForDistribution, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $locks, $usage, $publisherForUsage);
                break;
            case DistributionBuildAction::Dispatch:
                $this->dispatch($payload->id, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $locks, $usage, $publisherForUsage);
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
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Cache $cache,
        callable $locks,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): void {
        $meta = $payload->data['meta'] ?? [];
        $artifactId = (string) ($meta['artifactId'] ?? '');
        $callback = CallbackEvent::tryFrom($payload->event);
        if ($callback === null) {
            return;
        }

        if ($artifactId === '') {
            if ((string) ($meta['buildId'] ?? '') !== '' && ($meta['stage'] ?? '') === DistributionBuilds::STAGE_BUILD) {
                $this->progress($payload, $meta, $callback, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $cache, $locks, $usage, $publisherForUsage);
            }

            return;
        }

        if ($callback === CallbackEvent::Log) {
            return;
        }

        Span::add('artifact.id', $artifactId);
        Span::add('job.stage', (string) ($meta['stage'] ?? ''));

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $artifactId), self::LOCK_TTL, function () use ($payload, $meta, $artifactId, $callback, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $cache, $usage, $publisherForUsage): void {
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
                $event instanceof JobArtifact => $this->onArtifact($dbForProject, $project, $artifact, $event, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
                $event instanceof JobExit => $this->onExit($dbForProject, $project, $artifact, $event, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
                $callback === CallbackEvent::Complete => $this->onComplete($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
                default => $artifact,
            };

            $this->follow($dbForProject, $project, $artifactId, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

            if ($payload->id !== '' && $cache->save($key, true, ttl: self::DEDUPE_TTL) === false) {
                throw new \RuntimeException('Failed to record processed distribution callback.');
            }
        }, self::LOCK_TIMEOUT);
    }

    private function reconcile(
        string $id,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Jobs $jobsForDistributionSigning,
        Jobs $jobsForDistribution,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        callable $locks,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): void {
        if (!$dbForProject->getDocument('distributionBuilds', $id)->isEmpty()) {
            $this->reconcileBuild($id, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $jobsForDistribution, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $locks, $usage, $publisherForUsage);

            return;
        }

        Span::add('artifact.id', $id);

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $id), self::LOCK_TTL, function () use ($id, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $jobsForDistributionSigning, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage): void {
            $this->reconcileArtifact($id, $project, $dbForProject, $distributionBuilds, $jobsForDistributionSigning, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

            $this->follow($dbForProject, $project, $id, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }, self::LOCK_TIMEOUT);
    }

    private function reconcileArtifact(
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
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): void {
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
            $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
            return;
        }

        if (\is_array($join[self::RESULT] ?? null) && ($join['exitCode'] ?? null) === 0 && ($join['completed'] ?? false) === true) {
            $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
            return;
        }

        if ($status === null) {
            if ($this->expired($artifact)) {
                $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_LOST, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
            }
            return;
        }

        if ($status->status === JobState::Failed || $status->status === JobState::Cancelled) {
            $this->finalize($dbForProject, $project, $artifact, false, $this->failure($artifact, $status), $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
            return;
        }

        if ($this->expired($artifact)) {
            $code = $status->status === JobState::Completed ? DistributionBuilds::ERROR_JOB_FAILED : DistributionBuilds::ERROR_JOB_TIMEOUT;
            $distributionBuilds->cancel($artifact);
            $this->finalize($dbForProject, $project, $artifact, false, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }
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
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        if ($event->artifactId === self::SIGNING) {
            $this->revoke($project, $artifact, $signing, $deviceForDistribution);
        }

        $failed = $event->status === self::ARTIFACT_FAILED;
        if (!$failed && $event->status !== self::ARTIFACT_SUCCESS) {
            return $artifact;
        }

        if ($event->artifactId !== self::RESULT) {
            if (!$failed) {
                return $artifact;
            }

            Span::add('job.artifact.id', $event->artifactId);
            Span::add('job.artifact.error', $event->error?->code->value ?? '');

            return $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        if ($failed || !\is_array($event->content)) {
            $artifact = $this->record($dbForProject, $artifact, ['failed' => true]);

            if (($this->join($artifact)['exitCode'] ?? null) === 0) {
                return $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
            }

            return $artifact;
        }

        $artifact = $this->record($dbForProject, $artifact, [self::RESULT => $event->content]);

        return $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
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
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $this->revoke($project, $artifact, $signing, $deviceForDistribution);

        $artifact = $this->record($dbForProject, $artifact, ['exitCode' => $exit->exitCode]);
        Span::add('job.exit', $exit->exitCode);

        if ($exit->exitCode !== 0) {
            $code = $exit->reason === self::TIMEOUT_REASON
                ? DistributionBuilds::ERROR_JOB_TIMEOUT
                : (DistributionBuilds::error((string) ($this->job($artifact)['stage'] ?? ''), $exit->exitCode) ?? DistributionBuilds::ERROR_JOB_FAILED);

            return $this->finalize($dbForProject, $project, $artifact, false, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        if (($this->join($artifact)['failed'] ?? false) === true) {
            return $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        return $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
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
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $artifact = $this->record($dbForProject, $artifact, ['completed' => true]);

        return $this->ready($dbForProject, $project, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
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
        Context $usage,
        UsagePublisher $publisherForUsage,
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
            DistributionBuilds::STAGE_INSPECT => $this->inspected($dbForProject, $project, $artifact, $result, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
            DistributionBuilds::STAGE_SIGN => $this->signed($dbForProject, $project, $artifact, $result, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
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
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $finalize = fn (Document $artifact, bool $success, string $code = ''): Document => $this->finalize($dbForProject, $project, $artifact, $success, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

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
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $finalize = fn (Document $artifact, bool $success, string $code = ''): Document => $this->finalize($dbForProject, $project, $artifact, $success, $code, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

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
        Context $usage,
        UsagePublisher $publisherForUsage,
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

        $sign = ($this->job($artifact)['stage'] ?? '') === DistributionBuilds::STAGE_SIGN;
        if ($sign) {
            $this->revoke($project, $artifact, $signing, $deviceForDistribution);
            $deviceForDistribution->deletePath('signed/' . $artifact->getId());
        }

        $schedules->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $artifact->getId());

        $artifact = $dbForProject->getDocument('distributionArtifacts', $artifact->getId());
        Span::add('artifact.status', (string) $artifact->getAttribute('status', ''));
        if ($code !== '') {
            Span::add('artifact.error', $code);
        }

        if ($applied === 0 || $artifact->isEmpty()) {
            return $artifact;
        }

        $this->dispatchUpdate($queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $project, $artifact);

        if ($success && $sign) {
            $app = $dbForProject->getDocument('distributionApps', (string) $artifact->getAttribute('appId', ''));
            if (!$app->isEmpty()) {
                DistributionUsage::publishSigning($usage, $app, $project, $publisherForUsage);
            }
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
        Document $subject,
        string $event = self::EVENT,
    ): void {
        $update = $queueForEvents
            ->reset()
            ->setProject($project)
            ->setEvent($event)
            ->setPayload($this->payload($subject));
        foreach ($this->params($subject) as $key => $value) {
            $update->setParam($key, $value);
        }

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

        $this->broadcast($queueForRealtime, $project, $subject, $event);
    }

    private function broadcast(Realtime $queueForRealtime, Document $project, Document $subject, string $event = self::EVENT): void
    {
        $queueForRealtime
            ->reset()
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent($event)
            ->setPayload($this->payload($subject));
        foreach ($this->params($subject) as $key => $value) {
            $queueForRealtime->setParam($key, $value);
        }

        $queueForRealtime->trigger();
    }

    /**
     * @return array<string, string>
     */
    private function params(Document $subject): array
    {
        return $subject->getCollection() === 'distributionBuilds'
            ? ['appId' => (string) $subject->getAttribute('appId', ''), 'buildId' => $subject->getId()]
            : ['appId' => (string) $subject->getAttribute('appId', ''), 'artifactId' => $subject->getId()];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Document $subject): array
    {
        return $subject->getCollection() === 'distributionBuilds'
            ? (new DistributionBuild())->payload($subject)
            : (new DistributionArtifact())->payload($subject);
    }

    private function dispatch(
        string $id,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        callable $locks,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): void {
        Span::add('build.id', $id);

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $id), self::LOCK_TTL, function () use ($id, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage): void {
            $build = $dbForProject->getDocument('distributionBuilds', $id);
            if ($build->isEmpty() || $build->getAttribute('status') !== BuildStatus::Waiting->value) {
                return;
            }

            $this->submit($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }, self::LOCK_TIMEOUT);
    }

    /**
     * A build that `Builds::dispatch()` failed is settled at once instead of on the next reconcile tick.
     */
    private function submit(
        Database $dbForProject,
        Document $project,
        Document $build,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $settle = fn (Document $build): Document => $this->settle($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        if ($app->isEmpty()) {
            return $this->conclude($dbForProject, $project, $build, false, DistributionBuilds::ERROR_BUILD_FAILED, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        try {
            $vcs = $this->vcs($app, $dbForPlatform, $vcsFactory, $installationTokens);
        } catch (\Throwable) {
            $vcs = null;
        }

        if ($vcs === null) {
            return $this->conclude($dbForProject, $project, $build, false, DistributionBuilds::ERROR_BUILD_SOURCE_FAILED, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        try {
            $build = $distributionBuilds->dispatch($build, $vcs);
        } catch (\Throwable $error) {
            $settle($build);

            throw $error;
        }

        if (\in_array($build->getAttribute('status'), DistributionBuilds::TERMINAL, true)) {
            return $settle($build);
        }

        $this->broadcast($queueForRealtime, $project, $build, self::BUILD_EVENT);

        return $build;
    }

    private function vcs(Document $app, Database $dbForPlatform, VcsFactory $vcsFactory, InstallationTokens $installationTokens): ?Git
    {
        $installationId = (string) $app->getAttribute('installationId', '');
        $installation = $installationId === '' ? new Document() : $dbForPlatform->getDocument('installations', $installationId);
        if ($installation->isEmpty()) {
            return null;
        }

        return $vcsFactory->fromInstallation($installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory));
    }

    /**
     * A build-stage callback: held under the build lock, de-duplicated, and
     * ignored when it names another attempt or job. Only logs still apply once
     * the build is terminal or signing, because events arrive out of order.
     *
     * @param array<string, mixed> $meta
     */
    private function progress(
        DistributionBuildMessage $payload,
        array $meta,
        CallbackEvent $callback,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Cache $cache,
        callable $locks,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): void {
        $buildId = (string) $meta['buildId'];
        Span::add('build.id', $buildId);
        Span::add('job.stage', DistributionBuilds::STAGE_BUILD);

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $buildId), self::LOCK_TTL, function () use ($payload, $meta, $buildId, $callback, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $cache, $usage, $publisherForUsage): void {
            $key = 'distribution-event-' . $project->getSequence() . '-' . $payload->id;
            if ($payload->id !== '' && $cache->load($key, self::DEDUPE_TTL) !== false) {
                return;
            }

            $build = $dbForProject->getDocument('distributionBuilds', $buildId);
            $jobId = (string) ($payload->data['jobId'] ?? '');
            if ($build->isEmpty()
                || (string) ($meta['attempt'] ?? '') !== (string) $build->getAttribute('attempt', 1)
                || $jobId === ''
                || $jobId !== (string) $build->getAttribute('jobId', '')) {
                return;
            }

            if ($callback !== CallbackEvent::Log
                && (\in_array($build->getAttribute('status'), DistributionBuilds::TERMINAL, true)
                    || $build->getAttribute('stage') !== DistributionBuilds::STAGE_BUILD)) {
                return;
            }

            try {
                $event = match ($callback) {
                    CallbackEvent::Log => JobLog::fromArray($payload->data),
                    CallbackEvent::Artifact => JobArtifact::fromArray($payload->data),
                    CallbackEvent::Exit => JobExit::fromArray($payload->data),
                    default => null,
                };
            } catch (ClientException) {
                return;
            }

            match (true) {
                $callback === CallbackEvent::Start => $this->onBuildStart($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime),
                $event instanceof JobLog => $this->onLog($dbForProject, $project, $build, $event, $payload->id, $queueForRealtime),
                $event instanceof JobArtifact => $this->onBuildArtifact($dbForProject, $project, $build, $event, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
                $event instanceof JobExit => $this->onBuildExit($dbForProject, $project, $build, $event, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
                $callback === CallbackEvent::Complete => $this->onBuildComplete($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
                default => $build,
            };

            if ($payload->id !== '' && $cache->save($key, true, ttl: self::DEDUPE_TTL) === false) {
                throw new \RuntimeException('Failed to record processed distribution callback.');
            }
        }, self::LOCK_TIMEOUT);
    }

    protected function onBuildStart(
        Database $dbForProject,
        Document $project,
        Document $build,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
    ): Document {
        $started = $this->start($dbForProject, $build);
        if ($started->getAttribute('status') === $build->getAttribute('status')) {
            return $started;
        }

        $this->broadcast($queueForRealtime, $project, $started, self::BUILD_EVENT);
        $this->report($dbForProject, $started, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens);

        return $started;
    }

    /**
     * One realtime message per callback, for the last stored chunk: the Console
     * fetches the rest through `listBuildLogs`, which is the source of truth.
     */
    protected function onLog(Database $dbForProject, Document $project, Document $build, JobLog $log, string $eventId, Realtime $queueForRealtime): Document
    {
        if ($build->getAttribute('stage') === DistributionBuilds::STAGE_BUILD) {
            $build = $this->start($dbForProject, $build);
        }

        $size = (int) $build->getAttribute('logsSize', 0);
        $logs = new Logs($this->secrets($dbForProject, $project, $build), (int) System::getEnv('_APP_DISTRIBUTION_LOGS_LIMIT', self::LOGS_LIMIT));
        $chunks = $logs->admit($size, $logs->chunks($log->lines));
        $sequence = Logs::sequence($eventId);

        $last = new Document();
        $bytes = 0;
        $truncated = false;
        foreach ($chunks as $chunk => $content) {
            try {
                $last = $dbForProject->createDocument('distributionLogs', new Document([
                    '$id' => $eventId === '' ? Id::unique() : \substr(\hash('sha256', "{$eventId}:{$chunk}"), 0, 36),
                    'buildId' => $build->getId(),
                    'buildInternalId' => (string) $build->getSequence(),
                    'sequence' => $sequence,
                    'chunk' => $chunk,
                    'stage' => Stage::Build->value,
                    'content' => $content,
                ]));
            } catch (Duplicate) {
                continue;
            }

            $bytes += \strlen($content);
            $truncated = $content === Logs::TRUNCATED;
        }

        if ($bytes === 0) {
            return $build;
        }

        $build = $dbForProject->increaseDocumentAttribute('distributionBuilds', $build->getId(), 'logsSize', $logs->increment($size, $bytes, $truncated));

        $queueForRealtime
            ->reset()
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent(self::LOG_EVENT)
            ->setParam('appId', (string) $build->getAttribute('appId', ''))
            ->setParam('buildId', $build->getId())
            ->setParam('logId', $last->getId())
            ->setPayload([...(new DistributionLog())->payload($last), 'chunks' => \count($chunks)])
            ->trigger();

        return $build;
    }

    /**
     * A failed pre-job input fails the build at once. A failed output fails it
     * at once after the exit, and is recorded before it, so the exit cannot
     * succeed or dispatch the build again (RFC §8.6).
     */
    protected function onBuildArtifact(
        Database $dbForProject,
        Document $project,
        Document $build,
        JobArtifact $event,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $conclude = fn (Document $build, string $code): Document => $this->conclude($dbForProject, $project, $build, false, $code, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

        $failed = $event->status === self::ARTIFACT_FAILED;
        if (!$failed && $event->status !== self::ARTIFACT_SUCCESS) {
            return $build;
        }

        if (!$failed) {
            return \in_array($event->artifactId, self::OUTPUTS, true)
                ? $this->built($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage)
                : $build;
        }

        Span::add('job.artifact.id', $event->artifactId);
        Span::add('job.artifact.error', $event->error?->code->value ?? '');

        return match ($event->artifactId) {
            self::SOURCE, self::EXTRACT => $conclude($build, DistributionBuilds::ERROR_BUILD_SOURCE_FAILED),
            self::CACHE_PULL => $this->uncache($dbForProject, $build),
            self::OUTPUT, self::RESULT, self::RESULT_COPY => $build->getAttribute('duration') === null ? $this->undelivered($dbForProject, $build, $event->artifactId) : $conclude($build, DistributionBuilds::ERROR_BUILD_FAILED),
            default => $build,
        };
    }

    /**
     * A `-1` exit is every failure of the job's pod or container, so it is dispatched
     * again only when it came early and no pre-job input failed.
     */
    protected function onBuildExit(
        Database $dbForProject,
        Document $project,
        Document $build,
        JobExit $exit,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        Span::add('job.exit', $exit->exitCode);

        $duration = $exit->durationSeconds !== null && $exit->durationSeconds > 0 ? (int) \round($exit->durationSeconds) : DistributionBuilds::runtime($build);

        if ($exit->exitCode === 0) {
            $build = $this->stamp($dbForProject, $build, ['duration' => $duration]);

            return $this->built($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        $timeout = (int) $build->getAttribute('timeout', 0);
        $code = match (true) {
            $exit->error?->code === ErrorCode::JobOom => DistributionBuilds::ERROR_BUILD_OUT_OF_MEMORY,
            $exit->reason === self::TIMEOUT_REASON || $this->elapsed((string) $build->getAttribute('dispatchedAt', ''), $timeout + DistributionBuilds::SETUP) => DistributionBuilds::ERROR_BUILD_TIMEOUT,
            $exit->exitCode !== -1 => DistributionBuilds::error(DistributionBuilds::STAGE_BUILD, $exit->exitCode) ?? DistributionBuilds::ERROR_BUILD_FAILED,
            default => null,
        };

        if ($code === null && $this->failures($build) !== []) {
            $code = DistributionBuilds::ERROR_BUILD_FAILED;
        }

        if ($code === null) {
            if ($this->preJob((string) ($exit->reason ?? ''))) {
                if (!empty($build->getAttribute('redispatchAt'))) {
                    return $build;
                }

                $code = DistributionBuilds::ERROR_BUILD_SOURCE_FAILED;
            } elseif ((int) $build->getAttribute('attempt', 1) < DistributionBuilds::MAX_ATTEMPTS && !$this->elapsed($this->started($build), \intdiv($timeout, 2))) {
                return $this->retry($dbForProject, $project, $build, $duration, $usage, $publisherForUsage);
            } else {
                $code = DistributionBuilds::ERROR_BUILD_FAILED;
            }
        }

        $build = $this->stamp($dbForProject, $build, ['duration' => $duration]);

        return $this->conclude($dbForProject, $project, $build, false, $code, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
    }

    protected function onBuildComplete(
        Database $dbForProject,
        Document $project,
        Document $build,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $build = $this->stamp($dbForProject, $build, ['endedAt' => DateTime::now()]);

        return $this->built($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
    }

    /**
     * The terminal write of a build, filtered on a non-terminal status so it applies
     * once, then `settle()`. Takes no lock: the artifact path calls it under the
     * artifact lock, and a lock on the build there would nest the two.
     */
    protected function conclude(
        Database $dbForProject,
        Document $project,
        Document $build,
        bool $success,
        string $code,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $build = $dbForProject->getDocument('distributionBuilds', $build->getId());
        if ($build->isEmpty()) {
            return $build;
        }

        $code = $success ? '' : $code;
        $dbForProject->updateDocuments('distributionBuilds', new Document([
            'status' => $success ? BuildStatus::Ready->value : BuildStatus::Failed->value,
            'errorCode' => $code,
            'errorMessage' => $code === '' ? '' : (DistributionBuilds::message($code) ?: (self::MESSAGES[$code] ?? '')),
            'endedAt' => $build->getAttribute('endedAt') ?: DateTime::now(),
            'duration' => $build->getAttribute('duration') ?? DistributionBuilds::runtime($build),
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', DistributionBuilds::ACTIVE),
        ]);

        if ($code !== '') {
            Span::add('build.error', $code);
        }

        return $this->settle($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
    }

    /**
     * Applies once per terminal build, through the `meteredAt` filter, whoever made it
     * terminal. Never stops the schedule: reconcile does that once the job is gone.
     * A missing app skips only the steps scoped to it.
     */
    private function settle(
        Database $dbForProject,
        Document $project,
        Document $build,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $build = $dbForProject->getDocument('distributionBuilds', $build->getId());
        if ($build->isEmpty() || !\in_array($build->getAttribute('status'), DistributionBuilds::TERMINAL, true)) {
            return $build;
        }

        $metered = $dbForProject->updateDocuments('distributionBuilds', new Document([
            'meteredAt' => DateTime::now(),
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', DistributionBuilds::TERMINAL),
            Query::isNull('meteredAt'),
        ]);
        if ($metered === 0) {
            return $build;
        }

        $build = $dbForProject->getDocument('distributionBuilds', $build->getId());
        $status = (string) $build->getAttribute('status', '');
        Span::add('build.status', $status);

        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        if (!$app->isEmpty()) {
            DistributionUsage::publishBuild($usage, $app, $build, $project, $publisherForUsage);

            $dbForProject->updateDocuments('distributionApps', new Document([
                'latestBuildStatus' => $status,
            ]), [
                Query::equal('$id', [$app->getId()]),
                Query::equal('latestBuildId', [$build->getId()]),
            ]);

            $this->report($dbForProject, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens);
        }

        if ($status === BuildStatus::Canceled->value) {
            $this->broadcast($queueForRealtime, $project, $build, self::BUILD_EVENT);
        } else {
            $this->dispatchUpdate($queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $project, $build, self::BUILD_EVENT);
        }

        return $build;
    }

    /**
     * The join: a clean exit, the complete event and both stored files. Every
     * callback and reconcile call it again, so each step is idempotent: the
     * artifact id is derived from the build, and a moved bundle is found at its
     * destination.
     */
    private function built(
        Database $dbForProject,
        Document $project,
        Document $build,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $conclude = fn (Document $build, bool $success, string $code = ''): Document => $this->conclude($dbForProject, $project, $build, $success, $code, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

        $build = $dbForProject->getDocument('distributionBuilds', $build->getId());
        if ($build->isEmpty()
            || \in_array($build->getAttribute('status'), DistributionBuilds::TERMINAL, true)
            || $build->getAttribute('stage') !== DistributionBuilds::STAGE_BUILD
            || $build->getAttribute('duration') === null) {
            return $build;
        }

        if ($this->failures($build) !== []) {
            return $conclude($build, false, DistributionBuilds::ERROR_BUILD_FAILED);
        }

        if (empty($build->getAttribute('endedAt'))) {
            return $build;
        }

        $output = DistributionBuilds::output($build->getId());
        $artifactId = self::artifact($build);
        $unsigned = $deviceForDistribution->getPath("{$output}/app-unsigned.aab");
        $path = $deviceForDistribution->getPath(DistributionBuilds::path($artifactId, Type::Aab->value));
        $copy = $deviceForDistribution->getPath("{$output}/result.json");
        $moved = !$deviceForDistribution->exists($unsigned);
        if (!$deviceForDistribution->exists($copy) || ($moved && !$deviceForDistribution->exists($path))) {
            return $build;
        }

        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        $target = $dbForProject->getDocument('distributionTargets', (string) $build->getAttribute('targetId', ''));
        if ($app->isEmpty() || $target->isEmpty()) {
            return $conclude($build, false, DistributionBuilds::ERROR_BUILD_FAILED);
        }

        if ($deviceForDistribution->getFileSize($copy) > Result::BYTES) {
            return $conclude($build, false, DistributionBuilds::ERROR_BUILD_OUTPUT_INVALID);
        }

        try {
            $result = Result::parse((string) $deviceForDistribution->read($copy), $target, $build);
        } catch (\UnexpectedValueException $error) {
            Span::add('build.result.error', $error->getMessage());

            return $conclude($build, false, DistributionBuilds::ERROR_BUILD_OUTPUT_INVALID);
        }

        $identifier = (string) $target->getAttribute('identifier', '');
        $versionCode = $result->versionCode;
        $versionName = $result->versionName;

        $created = false;
        try {
            $artifact = $dbForProject->createDocument('distributionArtifacts', new Document([
                '$id' => $artifactId,
                'appId' => $app->getId(),
                'appInternalId' => (string) $app->getSequence(),
                'targetId' => $target->getId(),
                'targetInternalId' => (string) $target->getSequence(),
                'platform' => Platform::Android->value,
                'type' => Type::Aab->value,
                'source' => Source::Build->value,
                'buildId' => $build->getId(),
                'buildInternalId' => (string) $build->getSequence(),
                'status' => Status::Processing->value,
                'path' => $path,
                'name' => "{$identifier}-{$versionCode}.aab",
                'size' => $deviceForDistribution->getFileSize($moved ? $path : $unsigned),
                'chunksTotal' => 1,
                'chunksUploaded' => 1,
                'identifier' => $identifier,
                'versionName' => $versionName,
                'versionCode' => $versionCode,
                'signed' => false,
                'expiresAt' => DateTime::addSeconds(new \DateTime(), (int) System::getEnv('_APP_DISTRIBUTION_ARTIFACT_RETENTION', DistributionBuilds::ARTIFACT_RETENTION)),
            ]));
            $created = true;
        } catch (Duplicate) {
            $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);
        }

        if (!$moved && !$deviceForDistribution->move($unsigned, $path)) {
            throw new \RuntimeException("Failed to store the bundle of build {$build->getId()}.");
        }

        $toolchain = $build->getAttribute('toolchain') ?? [];
        $build = $dbForProject->updateDocument('distributionBuilds', $build->getId(), new Document([
            'artifactId' => $artifact->getId(),
            'artifactInternalId' => (string) $artifact->getSequence(),
            'toolchain' => [
                ...$toolchain,
                ...$result->toolchain,
                'cache' => ($toolchain['cache'] ?? false) === true,
            ],
            'versionName' => $versionName,
        ]));

        if ($created) {
            $this->dispatchUpdate($queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $project, $artifact, self::ARTIFACT_CREATE);
        }

        if ($artifact->getAttribute('status') !== Status::Processing->value) {
            return $conclude($build, $artifact->getAttribute('status') === Status::Ready->value, (string) $artifact->getAttribute('errorCode', '') ?: DistributionBuilds::ERROR_BUILD_FAILED);
        }

        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', (string) $target->getAttribute('signingIdentityId', ''));
        if ($signingIdentity->isEmpty() || $signingIdentity->getAttribute('platform') !== Platform::Android->value) {
            $this->finalize($dbForProject, $project, $artifact, true, '', $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

            return $conclude($build, true);
        }

        $build = $dbForProject->updateDocument('distributionBuilds', $build->getId(), new Document([
            'status' => BuildStatus::Signing->value,
            'stage' => DistributionBuilds::STAGE_SIGN,
        ]));
        $this->broadcast($queueForRealtime, $project, $build, self::BUILD_EVENT);

        $artifact = $distributionBuilds->sign($artifact, $target, $signingIdentity);
        $this->broadcast($queueForRealtime, $project, $artifact);

        return $build;
    }

    /**
     * Once a build's artifact is terminal, the build follows it. Called under the
     * artifact lock with filtered writes only, so the two locks never nest.
     */
    private function follow(
        Database $dbForProject,
        Document $project,
        string $artifactId,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): void {
        $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);
        $buildId = (string) $artifact->getAttribute('buildId', '');
        $status = $artifact->getAttribute('status');
        if ($artifact->isEmpty()
            || $artifact->getAttribute('source') !== Source::Build->value
            || $buildId === ''
            || !\in_array($status, [Status::Ready->value, Status::Invalid->value], true)) {
            return;
        }

        $build = $dbForProject->getDocument('distributionBuilds', $buildId);
        if ($build->isEmpty()) {
            return;
        }

        $ready = $status === Status::Ready->value;
        $this->conclude($dbForProject, $project, $build, $ready, $ready ? '' : ((string) $artifact->getAttribute('errorCode', '') ?: DistributionBuilds::ERROR_BUILD_FAILED), $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
    }

    /**
     * The only place a build's outputs are removed and its schedule stopped, once
     * its job is gone: a canceled Kubernetes job can still upload for its grace period.
     */
    private function reconcileBuild(
        string $id,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        Jobs $jobsForDistribution,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        callable $locks,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): void {
        Span::add('build.id', $id);

        $locks(DistributionBuilds::lock((string) $project->getSequence(), $id), self::LOCK_TTL, function () use ($id, $project, $dbForProject, $dbForPlatform, $distributionBuilds, $jobsForDistribution, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage): void {
            $conclude = fn (Document $build, bool $success, string $code = ''): Document => $this->conclude($dbForProject, $project, $build, $success, $code, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
            $built = fn (Document $build): Document => $this->built($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
            $submit = fn (Document $build): Document => $this->submit($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

            $build = $dbForProject->getDocument('distributionBuilds', $id);
            if ($build->isEmpty()) {
                return;
            }

            if (\in_array($build->getAttribute('status'), DistributionBuilds::TERMINAL, true)) {
                $build = $this->settle($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

                $ended = (string) ($build->getAttribute('endedAt') ?? '') ?: (string) $build->getUpdatedAt();
                $expired = $this->elapsed($ended, DistributionBuilds::BUILD_POST_BUDGET + self::GRACE);
                try {
                    $gone = $this->gone($build, $jobsForDistribution);
                } catch (\Throwable $error) {
                    Span::add('build.job.error', $error->getMessage());
                    $gone = false;
                }

                if (!$gone
                    && ($expired || $build->getAttribute('status') === BuildStatus::Canceled->value)
                    && $build->getAttribute('stage') === DistributionBuilds::STAGE_BUILD) {
                    try {
                        $distributionBuilds->cancel($build);
                    } catch (\Throwable) {
                        // A job the orchestrator cannot delete runs to its own deadline.
                    }
                }

                if ($gone || $expired) {
                    $deviceForDistribution->deletePath(DistributionBuilds::output($id));
                    $schedules->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $id);
                }

                return;
            }

            if ($build->getAttribute('runner') === Runner::Pool->value) {
                return;
            }

            if ($build->getAttribute('stage') === DistributionBuilds::STAGE_SIGN) {
                $artifact = $dbForProject->getDocument('distributionArtifacts', (string) $build->getAttribute('artifactId', ''));
                $status = $artifact->getAttribute('status');
                match (true) {
                    $artifact->isEmpty() => $conclude($build, false, DistributionBuilds::ERROR_BUILD_FAILED),
                    $status === Status::Ready->value => $conclude($build, true),
                    $status === Status::Invalid->value => $conclude($build, false, (string) $artifact->getAttribute('errorCode', '') ?: DistributionBuilds::ERROR_BUILD_FAILED),
                    ($this->job($artifact)['id'] ?? '') === '' => $this->resign($dbForProject, $project, $build, $artifact, $distributionBuilds, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage),
                    default => $build,
                };

                return;
            }

            if (!empty($build->getAttribute('redispatchAt'))) {
                $this->redispatch($build, $dbForProject, $project, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

                return;
            }

            $jobId = (string) $build->getAttribute('jobId', '');
            if ($jobId === '') {
                $submit($build);

                return;
            }

            $status = $this->status($jobsForDistribution, $jobId);
            Span::add('job.state', $status?->status->value ?? 'missing');

            $timeout = (int) $build->getAttribute('timeout', 0) + DistributionBuilds::SETUP;
            $lost = $this->elapsed($this->started($build), $timeout + DistributionBuilds::BUILD_POST_BUDGET + self::GRACE);

            if ($status?->status === JobState::Completed || ($status === null && $build->getAttribute('duration') !== null)) {
                try {
                    $build = $built($this->stamp($dbForProject, $build, [
                        'duration' => DistributionBuilds::runtime($build),
                        'endedAt' => DateTime::now(),
                    ]));
                } catch (\Throwable $error) {
                    if (!$lost) {
                        throw $error;
                    }

                    Span::add('build.join.error', $error->getMessage());
                }

                if ($lost && !\in_array($build->getAttribute('status'), DistributionBuilds::TERMINAL, true) && $build->getAttribute('stage') === DistributionBuilds::STAGE_BUILD) {
                    $conclude($build, false, DistributionBuilds::ERROR_BUILD_LOST);
                }

                return;
            }

            if ($status === null) {
                match (true) {
                    $lost => $conclude($build, false, DistributionBuilds::ERROR_BUILD_LOST),
                    empty($build->getAttribute('startedAt')) => $submit($build),
                    default => $build,
                };

                return;
            }

            if ($status->status === JobState::Failed || $status->status === JobState::Cancelled) {
                $deadline = \str_contains($status->error ?? '', self::DEADLINE_EXCEEDED) || $this->elapsed((string) $build->getAttribute('dispatchedAt', ''), $timeout);
                $conclude($build, false, $deadline ? DistributionBuilds::ERROR_BUILD_TIMEOUT : (DistributionBuilds::error(DistributionBuilds::STAGE_BUILD, $status->exitCode ?? 1) ?? DistributionBuilds::ERROR_BUILD_FAILED));

                return;
            }

            if ($lost) {
                $distributionBuilds->cancel($build);
                $conclude($build, false, DistributionBuilds::ERROR_BUILD_TIMEOUT);
            }
        }, self::LOCK_TIMEOUT);
    }

    /**
     * The previous attempt's job is deleted first, so nothing it still sends can apply.
     */
    private function redispatch(
        Document $build,
        Database $dbForProject,
        Document $project,
        Database $dbForPlatform,
        DistributionBuilds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $conclude = fn (Document $build, string $code): Document => $this->conclude($dbForProject, $project, $build, false, $code, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        try {
            $vcs = $app->isEmpty() ? null : $this->vcs($app, $dbForPlatform, $vcsFactory, $installationTokens);
        } catch (\Throwable) {
            $vcs = null;
        }

        if ($vcs === null) {
            return $conclude($build, DistributionBuilds::ERROR_BUILD_SOURCE_FAILED);
        }

        try {
            $distributionBuilds->cancel($build);
        } catch (\Throwable) {
            // A job that could not be deleted belongs to an older attempt, whose callbacks are ignored.
        }

        $attempt = (int) $build->getAttribute('attempt', 1);
        try {
            $next = $distributionBuilds->redispatch($build, $vcs);
        } catch (\Throwable $error) {
            $this->settle($dbForProject, $project, $build, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);

            throw $error;
        }

        if (\in_array($next->getAttribute('status'), DistributionBuilds::TERMINAL, true)) {
            return $this->settle($dbForProject, $project, $next, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        if ((int) $next->getAttribute('attempt', 1) === $attempt) {
            return $conclude($next, DistributionBuilds::ERROR_BUILD_FAILED);
        }

        $this->broadcast($queueForRealtime, $project, $next, self::BUILD_EVENT);

        return $next;
    }

    /**
     * The build lock covers both writes of the hand-over to signing, so a sign stage
     * whose artifact has no job means the worker stopped between them.
     */
    private function resign(
        Database $dbForProject,
        Document $project,
        Document $build,
        Document $artifact,
        DistributionBuilds $distributionBuilds,
        Device $deviceForDistribution,
        Schedules $schedules,
        Signing $signing,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Context $usage,
        UsagePublisher $publisherForUsage,
    ): Document {
        $target = $dbForProject->getDocument('distributionTargets', (string) $build->getAttribute('targetId', ''));
        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', (string) $target->getAttribute('signingIdentityId', ''));
        if ($target->isEmpty() || $signingIdentity->isEmpty()) {
            return $this->finalize($dbForProject, $project, $artifact, false, DistributionBuilds::ERROR_JOB_FAILED, $deviceForDistribution, $schedules, $signing, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $usage, $publisherForUsage);
        }

        return $distributionBuilds->sign($artifact, $target, $signingIdentity);
    }

    /**
     * The log fallback for a lost start event stamps `startedAt` too.
     */
    private function start(Database $dbForProject, Document $build): Document
    {
        $startable = [BuildStatus::Waiting->value, BuildStatus::Processing->value, BuildStatus::Building->value];
        $status = $build->getAttribute('status');
        if (!\in_array($status, $startable, true)
            || (!empty($build->getAttribute('startedAt')) && $status === BuildStatus::Building->value)) {
            return $build;
        }

        $dbForProject->updateDocuments('distributionBuilds', new Document([
            'status' => BuildStatus::Building->value,
            'startedAt' => $build->getAttribute('startedAt') ?: DateTime::now(),
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', $startable),
        ]);

        return $dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    /**
     * Writes each change only while the build has none yet and is not terminal.
     *
     * @param array<string, mixed> $changes
     */
    private function stamp(Database $dbForProject, Document $build, array $changes): Document
    {
        $changes = \array_filter($changes, static fn (string $key): bool => $build->getAttribute($key) === null, ARRAY_FILTER_USE_KEY);
        if ($changes === []) {
            return $build;
        }

        $dbForProject->updateDocuments('distributionBuilds', new Document($changes), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', DistributionBuilds::ACTIVE),
        ]);

        return $dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    /**
     * The cache object vanished between the existence check and the pull: one
     * more dispatch without the cache, decided by reconcile once the job is gone.
     */
    private function uncache(Database $dbForProject, Document $build): Document
    {
        $toolchain = $build->getAttribute('toolchain') ?? [];
        if (($toolchain['cacheFailed'] ?? false) === true) {
            return $build;
        }

        $dbForProject->updateDocuments('distributionBuilds', new Document([
            'redispatchAt' => DateTime::now(),
            'toolchain' => [...$toolchain, 'cacheFailed' => true],
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', DistributionBuilds::ACTIVE),
        ]);

        return $dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    /**
     * @return list<string> Post-job uploads of the current attempt that failed
     */
    private function failures(Document $build): array
    {
        $failed = ($build->getAttribute('toolchain') ?? [])['failed'] ?? [];

        return ($failed['attempt'] ?? null) === (int) $build->getAttribute('attempt', 1) ? ($failed['artifacts'] ?? []) : [];
    }

    private function undelivered(Database $dbForProject, Document $build, string $artifactId): Document
    {
        $failures = $this->failures($build);
        if (\in_array($artifactId, $failures, true)) {
            return $build;
        }

        $dbForProject->updateDocuments('distributionBuilds', new Document([
            'toolchain' => [
                ...($build->getAttribute('toolchain') ?? []),
                'failed' => ['attempt' => (int) $build->getAttribute('attempt', 1), 'artifacts' => [...$failures, $artifactId]],
            ],
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', DistributionBuilds::ACTIVE),
        ]);

        return $dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    /**
     * Marks the attempt for re-dispatch on the next reconcile tick, metering
     * its compute once: the filter applies to one exit of the attempt only.
     */
    private function retry(Database $dbForProject, Document $project, Document $build, int $duration, Context $usage, UsagePublisher $publisherForUsage): Document
    {
        $marked = $dbForProject->updateDocuments('distributionBuilds', new Document([
            'redispatchAt' => DateTime::now(),
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', DistributionBuilds::ACTIVE),
            Query::isNull('redispatchAt'),
        ]);

        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        if ($marked > 0 && !$app->isEmpty()) {
            DistributionUsage::publishAttempt($usage, $app, new Document([...$build->getArrayCopy(), 'duration' => $duration]), $project, $publisherForUsage);
        }

        return $dbForProject->getDocument('distributionBuilds', $build->getId());
    }

    private function preJob(string $reason): bool
    {
        foreach (self::PRE_JOB_FAILURES as $prefix) {
            if (\str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Values of secret project and app variables, and the target's signing passwords.
     *
     * @return list<string>
     */
    private function secrets(Database $dbForProject, Document $project, Document $build): array
    {
        $key = $project->getSequence() . ':' . $build->getId() . ':' . $build->getAttribute('attempt', 1);
        $now = \time();
        $cached = $this->secrets[$key] ?? null;
        if ($cached !== null && $cached['expiresAt'] > $now) {
            return $cached['values'];
        }

        unset($this->secrets[$key]);
        $secrets = $this->load($dbForProject, $build);

        $this->secrets[$key] = ['expiresAt' => $now + self::SECRETS_TTL, 'values' => $secrets];
        while (\count($this->secrets) > self::SECRETS_LIMIT) {
            unset($this->secrets[\array_key_first($this->secrets)]);
        }

        return $secrets;
    }

    /**
     * @return list<string>
     */
    private function load(Database $dbForProject, Document $build): array
    {
        $variables = [
            ...$dbForProject->find('variables', [
                Query::equal('resourceType', ['project']),
                Query::equal('secret', [true]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]),
            ...$dbForProject->find('variables', [
                Query::equal('resourceType', [RESOURCE_TYPE_DISTRIBUTION_APPS]),
                Query::equal('resourceInternalId', [(string) $build->getAttribute('appInternalId', '')]),
                Query::equal('secret', [true]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]),
        ];

        $secrets = \array_map(static fn (Document $variable): string => (string) $variable->getAttribute('value', ''), $variables);

        $target = $dbForProject->getDocument('distributionTargets', (string) $build->getAttribute('targetId', ''));
        $signingIdentityId = (string) $target->getAttribute('signingIdentityId', '');
        $signingIdentity = $signingIdentityId === '' ? new Document() : $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);
        if (!$signingIdentity->isEmpty()) {
            Span::add('signing_identity.decrypt', $signingIdentity->getId());
            $bundle = Signing::bundle($signingIdentity);
            \array_push($secrets, $bundle['storePassword'], $bundle['keyPassword']);
        }

        return $secrets;
    }

    /**
     * Best effort, as `Builds::report()` is.
     */
    private function report(Database $dbForProject, Document $build, Database $dbForPlatform, DistributionBuilds $distributionBuilds, VcsFactory $vcsFactory, InstallationTokens $installationTokens): void
    {
        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        if ($app->isEmpty()) {
            return;
        }

        try {
            $vcs = $this->vcs($app, $dbForPlatform, $vcsFactory, $installationTokens);
        } catch (\Throwable) {
            return;
        }

        if ($vcs !== null) {
            $distributionBuilds->report($app, $build, $vcs);
        }
    }

    private function gone(Document $build, Jobs $jobsForDistribution): bool
    {
        $jobId = (string) $build->getAttribute('jobId', '');

        return $build->getAttribute('runner') === Runner::Pool->value
            || $jobId === ''
            || $this->status($jobsForDistribution, $jobId) === null;
    }

    /**
     * Null for a job that does not exist: never created, canceled or expired.
     */
    private function status(Jobs $jobs, string $jobId): ?JobStatus
    {
        try {
            return $jobs->get($jobId);
        } catch (ApiException $error) {
            if ($error->statusCode !== 404) {
                throw $error;
            }

            return null;
        }
    }

    private function started(Document $build): string
    {
        return (string) ($build->getAttribute('startedAt') ?? '')
            ?: (string) ($build->getAttribute('dispatchedAt') ?? '')
            ?: (string) $build->getUpdatedAt();
    }

    /**
     * Derived from the build, so a join retried after a crash finds the row it created.
     */
    private static function artifact(Document $build): string
    {
        return \substr(\hash('sha256', "{$build->getId()}:artifact"), 0, 36);
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
