<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Message\Jobs as JobsMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Usage\Build as BuildUsage;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Response\Model\Deployment;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;

/**
 * Applies open-runtimes jobs-service callbacks to a deployment. The API
 * callback endpoint verifies + enqueues the CloudEvent; this worker owns the
 * deployment state transitions (log streaming and the ready/failed outcome)
 * that the executor path performs inline in the Builds worker.
 *
 * Callbacks arrive concurrently and out of order (one log line each), so
 * processing for a deployment is serialized under a per-deployment lock — that
 * keeps its buildLogs a clean, monotonic append while letting different
 * deployments build in parallel. Callbacks are at-least-once, so events are
 * de-duplicated on the CloudEvent id (inside the lock, so dedup + apply is
 * atomic and a lock timeout retries cleanly).
 */
class Jobs extends Action
{
    private const DEDUPE_TTL = 3600;
    private const LOCK_TTL = 30;
    private const LOCK_TIMEOUT = 10.0;

    public static function getName(): string
    {
        return 'jobs';
    }

    public function __construct()
    {
        $this
            ->desc('Jobs worker')
            ->groups(['jobs'])
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForRealtime')
            ->inject('queueForEvents')
            ->inject('queueForWebhooks')
            ->inject('publisherForFunctions')
            ->inject('publisherForUsage')
            ->inject('usage')
            ->inject('deviceForBuilds')
            ->inject('vcsFactory')
            ->inject('cache')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        UsagePublisher $publisherForUsage,
        UsageContext $usage,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        callable $locks,
    ): void {
        $event = JobsMessage::fromArray($message->getPayload());

        $deploymentId = $event->data['meta']['deploymentId'] ?? '';
        if ($deploymentId === '') {
            return;
        }

        $locks('jobs-deployment:' . $deploymentId, self::LOCK_TTL, function () use ($event, $project, $dbForProject, $dbForPlatform, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $publisherForUsage, $usage, $deviceForBuilds, $vcsFactory, $cache, $deploymentId): void {
            if ($event->id !== '') {
                $key = 'jobs-event-' . $event->id;
                if ($cache->load($key, self::DEDUPE_TTL) !== false) {
                    return; // already processed
                }
                $cache->save($key, true);
            }

            $deployment = $dbForProject->getDocument('deployments', $deploymentId);
            if ($deployment->isEmpty() || $deployment->getAttribute('status') === 'canceled') {
                return;
            }

            $statusBefore = $deployment->getAttribute('status');

            $deployment = match ($event->event) {
                'orchestrator.job.log' => $this->onLog($dbForProject, $deployment, $event->data),
                'orchestrator.job.artifact' => $this->onArtifact($dbForProject, $deployment, $event->data),
                'orchestrator.job.exit' => $this->onExit($dbForProject, $dbForPlatform, $project, $deployment, (int) ($event->data['exitCode'] ?? 0), $usage, $publisherForUsage, $deviceForBuilds, $vcsFactory, $cache),
                'orchestrator.job.complete' => $this->onComplete($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $deviceForBuilds, $vcsFactory, $cache),
                default => $deployment,
            };

            // Console realtime on every callback (log stream + status).
            $queueForRealtime
                ->setSubscribers(['console'])
                ->setProject($project)
                ->setEvent('functions.[functionId].deployments.[deploymentId].update')
                ->setParam('functionId', $deployment->getAttribute('resourceId'))
                ->setParam('deploymentId', $deploymentId)
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            // On a real terminal outcome (not a concurrently-canceled build),
            // notify webhooks + event-triggered functions of the deployment
            // update (mirrors the executor Builds worker). The transition can
            // land on either the exit (failures) or the complete callback
            // (success), so key off the status change rather than the event.
            if ($statusBefore !== $deployment->getAttribute('status') && \in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)) {
                $this->dispatchUpdate($queueForEvents, $queueForWebhooks, $publisherForFunctions, $project, $deployment);
            }
        }, self::LOCK_TIMEOUT);
    }

    private function onLog(Database $dbForProject, Document $deployment, array $data): Document
    {
        $lines = $data['lines'] ?? [];
        $chunk = \is_array($lines) ? \implode("\n", $lines) : (string) $lines;
        if ($chunk === '') {
            return $deployment;
        }

        $logs = $this->truncate($deployment->getAttribute('buildLogs', '') . $chunk . "\n");
        $update = ['buildLogs' => $logs];

        // First build output means the build is running: promote the queued
        // deployment to 'building' and stamp its start (mirrors the executor).
        // 'processing' occurs when the Builds worker prepared the source (the
        // template-into-repo push) before handing the build to the jobs-service.
        if (\in_array($deployment->getAttribute('status'), ['waiting', 'processing'], true)) {
            $update['status'] = 'building';
            $update['buildStartedAt'] = DateTime::now();
        }

        return $dbForProject->updateDocument('deployments', $deployment->getId(), new Document($update));
    }

    /**
     * Record a reported artifact size. Remote-source builds (templates / VCS)
     * fetch the source in the sidecar, so Appwrite can't size it the way the
     * uploaded-tarball path does — the job stats the downloaded archive and the
     * orchestrator reports its size here, which becomes the deployment's
     * sourceSize. This arrives before the exit callback, so finalize folds it
     * into totalSize.
     */
    private function onArtifact(Database $dbForProject, Document $deployment, array $data): Document
    {
        if (($data['artifactId'] ?? '') !== 'sourceSize' || ($data['status'] ?? '') !== 'success') {
            return $deployment;
        }

        // A stat artifact reports the file's byte size as its 'content'.
        $size = (int) ($data['content'] ?? 0);
        if ($size <= 0) {
            return $deployment;
        }

        // Recompute totalSize too: this callback can land either side of exit
        // (they arrive out of order), and finalize likewise derives totalSize
        // from the current sourceSize — so whichever runs last, the two agree.
        return $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
            'sourceSize' => $size,
            'totalSize' => $size + (int) $deployment->getAttribute('buildSize', 0),
        ]));
    }

    /**
     * A successful build needs both terminal callbacks before it can ready:
     * exit carries the code but fires before post-job artifacts are
     * delivered; complete confirms delivery but carries no code. Failures
     * short-circuit at exit — no output is needed to fail. The success join
     * is order-independent: each of exit(0)/complete leaves a marker, and
     * whichever finds the other's marker finalizes (markers live in cache,
     * applied under the per-deployment lock).
     */
    private function onExit(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        int $exitCode,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
    ): Document {
        if ($exitCode !== 0) {
            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, "Build failed with exit code {$exitCode}.", $usage, $publisherForUsage, $vcsFactory);
        }

        $cache->save('jobs-exit-' . $deployment->getId(), true);
        if ($cache->load('jobs-complete-' . $deployment->getId(), self::DEDUPE_TTL) !== false) {
            return $this->ready($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $deviceForBuilds, $vcsFactory);
        }

        return $deployment;
    }

    /**
     * The delivery half of the success join — see onExit. Emitted by the
     * jobs-service once the job's post-job artifacts have run, so on every
     * storage strategy the output is already where Appwrite reads it.
     */
    private function onComplete(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
    ): Document {
        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)) {
            return $deployment; // already finalized (a non-zero exit fails at the exit callback)
        }

        if ($cache->load('jobs-exit-' . $deployment->getId(), self::DEDUPE_TTL) !== false) {
            return $this->ready($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $deviceForBuilds, $vcsFactory);
        }

        $cache->save('jobs-complete-' . $deployment->getId(), true);

        return $deployment;
    }

    /**
     * Finalize a successful build: enforce the size limit against the stored
     * output, then mark ready.
     */
    private function ready(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
    ): Document {
        $path = (string) $deployment->getAttribute('buildPath', '');
        if ($path === '' || ! $deviceForBuilds->exists($path)) {
            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build produced no output artifact.', $usage, $publisherForUsage, $vcsFactory);
        }

        $size = $deviceForBuilds->getFileSize($path);

        $limit = (int) System::getEnv('_APP_COMPUTE_BUILD_SIZE_LIMIT', '2000000000');
        if ($limit !== 0 && $size > $limit) {
            $deviceForBuilds->delete($path);

            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build size should be less than ' . \number_format($limit / (1000 * 1000), 2) . ' MBs.', $usage, $publisherForUsage, $vcsFactory);
        }

        return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, true, '', $usage, $publisherForUsage, $vcsFactory, $size);
    }

    /**
     * Apply a terminal outcome (ready or failed) to the deployment. Owns
     * activation, usage and the latestDeployment pointer, mirroring the executor
     * Builds worker.
     */
    private function finalize(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        bool $success,
        string $message,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        VcsFactory $vcsFactory,
        int $buildSize = 0,
    ): Document {
        $function = $dbForProject->getDocument('functions', $deployment->getAttribute('resourceId'));

        $logs = $deployment->getAttribute('buildLogs', '');
        $trailer = $success
            ? "\033[90m[" . \date('H:i:s') . "] \033[90m[\033[0mappwrite\033[90m]\033[32m Deployment finished. \033[0m\n"
            : "\n" . ($message !== '' ? $message : 'Build failed.') . "\n";
        $update = [
            'status' => $success ? 'ready' : 'failed',
            'buildEndedAt' => DateTime::now(),
            'buildDuration' => $this->duration($deployment),
            'buildLogs' => $this->truncate($logs . $trailer),
        ];
        if ($success) {
            $update['buildSize'] = $buildSize;
            $update['totalSize'] = $buildSize + (int) $deployment->getAttribute('sourceSize', 0);
        }

        // Guard against a concurrent cancel (updateDeploymentStatus): only
        // transition if the build wasn't canceled between the read and this
        // write, so a late outcome can't resurrect a canceled build.
        $applied = $dbForProject->updateDocuments('deployments', new Document($update), [
            Query::equal('$id', [$deployment->getId()]),
            Query::notEqual('status', 'canceled'),
        ]);
        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

        if ($applied > 0 && $success && $deployment->getAttribute('activate') === true && ! $function->isEmpty()) {
            $this->activate($dbForProject, $dbForPlatform, $project, $function, $deployment);
        }

        // Count the build for usage/billing once it reached a terminal outcome
        // (mirrors the executor Builds worker); never for a concurrently-canceled
        // build (the guard above left it 'canceled').
        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true) && ! $function->isEmpty()) {
            BuildUsage::publish($usage, $function, $deployment, $project, $publisherForUsage);
        }

        // Keep the function's "latest deployment" pointer + status current, and
        // (re)activate its schedule so the scheduler enqueues cron executions —
        // both mirror the executor Builds worker on build completion.
        if (! $function->isEmpty()) {
            $this->updateLatestDeployment($dbForProject, $function);
            $this->schedule($dbForProject, $dbForPlatform, $function);
        }

        // Report the terminal outcome as a VCS commit status (jobs-built VCS
        // deployments only; best-effort). "pending" at build start is a follow-up.
        $status = $deployment->getAttribute('status');
        if (\in_array($status, ['ready', 'failed'], true) && ! $function->isEmpty()) {
            $this->gitStatus($vcsFactory, $dbForPlatform, $project, $function, $deployment, $status === 'ready' ? 'success' : 'failure');
        }

        return $deployment;
    }

    /**
     * Elapsed build seconds, rounded up so any finished build reports at least
     * 1 (mirrors the executor Builds worker). Callbacks arrive out of order, so
     * buildStartedAt (stamped by the first log callback) can be missing when a
     * terminal callback finalizes first — fall back to the deployment's
     * creation time rather than reporting 0.
     */
    private function duration(Document $deployment): int
    {
        $startedAt = $deployment->getAttribute('buildStartedAt', '') ?: $deployment->getCreatedAt();
        if (empty($startedAt)) {
            return 0;
        }

        try {
            $started = (float) (new \DateTimeImmutable($startedAt))->format('U.u');
        } catch (\Exception) {
            return 0;
        }

        return (int) \ceil(\max(0.0, \microtime(true) - $started));
    }

    /**
     * Post a VCS commit status for a VCS deployment (no-op for non-VCS builds
     * or silent mode). Best-effort — a failed status update never fails the build.
     */
    private function gitStatus(VcsFactory $vcsFactory, Database $dbForPlatform, Document $project, Document $function, Document $deployment, string $state): void
    {
        $commitHash = $deployment->getAttribute('providerCommitHash', '');
        if ($commitHash === '' || $function->getAttribute('providerSilentMode', false) === true) {
            return;
        }

        try {
            $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId', ''));
            $providerInstallationId = $installation->getAttribute('providerInstallationId', '');
            if ($providerInstallationId === '') {
                return;
            }
            $vcs = $vcsFactory->fromInstallation($installation);

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $hostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', ''));
            $region = $project->getAttribute('region', 'default');
            $targetUrl = "{$protocol}://{$hostname}/console/project-{$region}-{$project->getId()}/functions/function-{$function->getId()}";
            $message = $state === 'success' ? 'Build succeeded.' : 'Build failed.';
            $name = $function->getAttribute('name', '') . ' (' . $project->getAttribute('name', '') . ')';

            $vcs->updateCommitStatus(
                $deployment->getAttribute('providerRepositoryName', ''),
                $commitHash,
                $deployment->getAttribute('providerRepositoryOwner', ''),
                $state,
                $message,
                $targetUrl,
                $name,
            );
        } catch (\Throwable) {
            // Best-effort; the build outcome stands regardless of the status update.
        }
    }

    /**
     * Point the function at this deployment (auto-activate). Mirrors the
     * essential function activation from the Builds worker.
     */
    private function activate(Database $dbForProject, Database $dbForPlatform, Document $project, Document $function, Document $deployment): void
    {
        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document([
            'live' => true,
            'deploymentId' => $deployment->getId(),
            'deploymentInternalId' => $deployment->getSequence(),
            'deploymentCreatedAt' => $deployment->getCreatedAt(),
        ]));

        $dbForPlatform->forEach('rules', function (Document $rule) use ($dbForPlatform, $deployment) {
            $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                'deploymentId' => $deployment->getId(),
                'deploymentInternalId' => $deployment->getSequence(),
            ]));
        }, [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('type', ['deployment']),
            Query::equal('deploymentResourceInternalId', [$function->getSequence()]),
            Query::equal('deploymentResourceType', ['function']),
            Query::equal('trigger', ['manual']),
            Query::equal('deploymentVcsProviderBranch', ['']),
        ]);
    }

    /**
     * Refresh the function's latestDeployment* fields from its newest
     * deployment. Mirrors the Builds worker so the console reflects the current
     * build status.
     */
    private function updateLatestDeployment(Database $dbForProject, Document $function): void
    {
        $latest = $dbForProject->findOne('deployments', [
            Query::equal('resourceType', ['functions']),
            Query::equal('resourceInternalId', [$function->getSequence()]),
            Query::orderDesc('$createdAt'),
        ]);

        if ($latest->isEmpty()) {
            return;
        }

        $dbForProject->updateDocument('functions', $function->getId(), new Document([
            'latestDeploymentId' => $latest->getId(),
            'latestDeploymentInternalId' => $latest->getSequence(),
            'latestDeploymentCreatedAt' => $latest->getCreatedAt(),
            'latestDeploymentStatus' => $latest->getAttribute('status', ''),
        ]));
    }

    /**
     * (Re)activate the function's schedule document so the scheduler enqueues
     * cron executions. Mirrors the executor Builds worker: a schedule is active
     * only when the function has both a cron expression and an active
     * deployment. Re-reads the function so it sees a deploymentId just set by
     * activate().
     */
    private function schedule(Database $dbForProject, Database $dbForPlatform, Document $function): void
    {
        $scheduleId = $function->getAttribute('scheduleId', '');
        if ($scheduleId === '') {
            return;
        }

        $function = $dbForProject->getDocument('functions', $function->getId());
        $schedule = $dbForPlatform->getDocument('schedules', $scheduleId);
        if ($schedule->isEmpty()) {
            return;
        }

        $dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'resourceUpdatedAt' => DateTime::now(),
            'schedule' => $function->getAttribute('schedule', ''),
            'active' => ! empty($function->getAttribute('schedule')) && ! empty($function->getAttribute('deploymentId')),
        ]));
    }

    /**
     * Notify project webhooks and event-triggered functions of a deployment
     * update, mirroring the executor Builds worker's fan-out. queueForEvents
     * only builds the event; realtime is triggered separately in action().
     */
    private function dispatchUpdate(
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Document $project,
        Document $deployment,
    ): void {
        $model = new Deployment();
        $update = $queueForEvents
            ->setProject($project)
            ->setEvent('functions.[functionId].deployments.[deploymentId].update')
            ->setParam('functionId', $deployment->getAttribute('resourceId'))
            ->setParam('deploymentId', $deployment->getId())
            ->setPayload($deployment->getArrayCopy(\array_keys($model->getRules())));

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
    }

    private function truncate(string $logs): string
    {
        $limit = APP_LOG_LENGTH_LIMIT;
        if (\strlen($logs) <= $limit) {
            return $logs;
        }

        return \substr($logs, -$limit);
    }
}
