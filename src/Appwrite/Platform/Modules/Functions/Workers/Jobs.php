<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Appwrite\Compute\Job;
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
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

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
            ->inject('gitHub')
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
        GitHub $github,
        Cache $cache,
        callable $locks,
    ): void {
        $event = JobsMessage::fromArray($message->getPayload());

        $deploymentId = $event->data['meta']['deploymentId'] ?? '';
        if ($deploymentId === '') {
            return;
        }

        $locks('jobs-deployment:' . $deploymentId, self::LOCK_TTL, function () use ($event, $project, $dbForProject, $dbForPlatform, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $publisherForUsage, $usage, $deviceForBuilds, $github, $cache, $deploymentId): void {
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

            // The build writes its output onto the mounted volume before build.sh
            // exits, so the exit callback is a truthful completion signal.
            $finalizing = $event->event === 'orchestrator.job.exit';

            $deployment = match ($event->event) {
                'orchestrator.job.log' => $this->onLog($dbForProject, $deployment, $event->data),
                'orchestrator.job.artifact' => $this->onArtifact($dbForProject, $deployment, $event->data),
                'orchestrator.job.exit' => $this->onExit($dbForProject, $dbForPlatform, $project, $deployment, (int) ($event->data['exitCode'] ?? 0), $usage, $publisherForUsage, $deviceForBuilds, $github),
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
            // update (mirrors the executor Builds worker).
            if ($finalizing && \in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)) {
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
        if ($deployment->getAttribute('status') === 'waiting') {
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
     * Resolve the build-command exit into a terminal outcome. The output is
     * already on the mounted builds volume, so a zero exit enforces the size
     * limit against the on-disk artifact before readying; a non-zero exit fails.
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
        GitHub $github,
    ): Document {
        if ($exitCode !== 0) {
            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, "Build failed with exit code {$exitCode}.", $usage, $publisherForUsage, $github);
        }

        $path = Job::buildPath($project->getId(), $deployment->getId());
        $size = $deviceForBuilds->exists($path) ? $deviceForBuilds->getFileSize($path) : 0;

        $limit = (int) System::getEnv('_APP_COMPUTE_BUILD_SIZE_LIMIT', '2000000000');
        if ($limit !== 0 && $size > $limit) {
            $deviceForBuilds->delete($path);
            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build size should be less than ' . \number_format($limit / (1000 * 1000), 2) . ' MBs.', $usage, $publisherForUsage, $github);
        }

        return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, true, '', $usage, $publisherForUsage, $github, $size);
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
        GitHub $github,
        int $buildSize = 0,
    ): Document {
        $function = $dbForProject->getDocument('functions', $deployment->getAttribute('resourceId'));

        $logs = $deployment->getAttribute('buildLogs', '');
        $startedAt = \strtotime($deployment->getAttribute('buildStartedAt', '') ?: 'now') ?: \time();
        $trailer = $success
            ? "\033[90m[" . \date('H:i:s') . "] \033[90m[\033[0mappwrite\033[90m]\033[32m Deployment finished. \033[0m\n"
            : "\n" . ($message !== '' ? $message : 'Build failed.') . "\n";
        $update = [
            'status' => $success ? 'ready' : 'failed',
            'buildEndedAt' => DateTime::now(),
            'buildDuration' => \max(0, \time() - $startedAt),
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

        // Keep the function's "latest deployment" pointer + status current.
        if (! $function->isEmpty()) {
            $this->updateLatestDeployment($dbForProject, $function);
        }

        // Report the terminal outcome as a VCS commit status (jobs-built VCS
        // deployments only; best-effort). "pending" at build start is a follow-up.
        $status = $deployment->getAttribute('status');
        if (\in_array($status, ['ready', 'failed'], true) && ! $function->isEmpty()) {
            $this->gitStatus($github, $dbForPlatform, $project, $function, $deployment, $status === 'ready' ? 'success' : 'failure');
        }

        return $deployment;
    }

    /**
     * Post a GitHub commit status for a VCS deployment (no-op for non-VCS builds
     * or silent mode). Best-effort — a failed status update never fails the build.
     */
    private function gitStatus(GitHub $github, Database $dbForPlatform, Document $project, Document $function, Document $deployment, string $state): void
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
            $github->initializeVariables($providerInstallationId, System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY', ''), System::getEnv('_APP_VCS_GITHUB_APP_ID', ''));

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $hostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', ''));
            $region = $project->getAttribute('region', 'default');
            $targetUrl = "{$protocol}://{$hostname}/console/project-{$region}-{$project->getId()}/functions/function-{$function->getId()}";
            $message = $state === 'success' ? 'Build succeeded.' : 'Build failed.';
            $name = $function->getAttribute('name', '') . ' (' . $project->getAttribute('name', '') . ')';

            $github->updateCommitStatus(
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
