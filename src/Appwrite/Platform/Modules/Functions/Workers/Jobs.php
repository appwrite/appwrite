<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Appwrite\Deployment\Detection;
use Appwrite\Deployment\GitAction;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Message\Jobs as JobsMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Screenshot as ScreenshotPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Modules\Compute\Base;
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
 *
 * Handlers are protected extension points: downstream workers (e.g. cloud)
 * override finalize() and wrap parent:: — before it for work that must
 * precede 'ready' (edge distribution), after it for post-activation work.
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
            ->inject('publisherForScreenshots')
            ->inject('publisherForUsage')
            ->inject('usage')
            ->inject('deviceForBuilds')
            ->inject('vcsFactory')
            ->inject('cache')
            ->inject('locks')
            ->inject('platform')
            ->inject('plan')
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
        ScreenshotPublisher $publisherForScreenshots,
        UsagePublisher $publisherForUsage,
        UsageContext $usage,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        callable $locks,
        array $platform,
        array $plan,
    ): void {
        $event = JobsMessage::fromArray($message->getPayload());

        $deploymentId = $event->data['meta']['deploymentId'] ?? '';
        if ($deploymentId === '') {
            return;
        }

        $locks('jobs-deployment:' . $deploymentId, self::LOCK_TTL, function () use ($event, $project, $dbForProject, $dbForPlatform, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $publisherForScreenshots, $publisherForUsage, $usage, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan, $deploymentId): void {
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
                'orchestrator.job.log' => $this->onLog($dbForProject, $dbForPlatform, $project, $deployment, $event->data, $vcsFactory, $platform),
                'orchestrator.job.artifact' => $this->onArtifact($dbForProject, $dbForPlatform, $project, $deployment, $event->data, $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan),
                'orchestrator.job.exit' => $this->onExit($dbForProject, $dbForPlatform, $project, $deployment, (int) ($event->data['exitCode'] ?? 0), $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan),
                'orchestrator.job.complete' => $this->onComplete($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan),
                default => $deployment,
            };

            // Console realtime on every callback (log stream + status).
            $queueForRealtime
                ->setSubscribers(['console'])
                ->setProject($project)
                ->setEvent(self::event($deployment))
                ->setParam(self::resourceParam($deployment), $deployment->getAttribute('resourceId'))
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

    protected function onLog(Database $dbForProject, Database $dbForPlatform, Document $project, Document $deployment, array $data, VcsFactory $vcsFactory, array $platform): Document
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

        // Guarded like finalize: a concurrent cancel appends its own closing
        // log line, which a blind write here would clobber.
        $dbForProject->updateDocuments('deployments', new Document($update), [
            Query::equal('$id', [$deployment->getId()]),
            Query::notEqual('status', 'canceled'),
        ]);
        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());

        if (($update['status'] ?? '') === 'building' && $deployment->getAttribute('status') === 'building') {
            $this->gitAction('processing', $deployment, $project, $dbForProject, $dbForPlatform, $vcsFactory, $platform);
        }

        return $deployment;
    }

    /**
     * Record a reported artifact: 'sourceSize' (remote-source builds) becomes
     * the deployment's sourceSize; 'manifest' (site builds) is the output file
     * listing for adapter detection, saved as a marker that joins readiness.
     */
    protected function onArtifact(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        array $data,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        array $platform,
        array $plan,
    ): Document {
        if (($data['artifactId'] ?? '') === 'manifest') {
            // A failed manifest degrades to an empty listing (detection
            // skipped), never a failed build.
            $manifest = ($data['status'] ?? '') === 'success' ? ($data['content'] ?? null) : null;
            $files = \is_array($manifest) ? (array) ($manifest['files'] ?? []) : [];
            $cache->save('jobs-manifest-' . $deployment->getId(), ['files' => \array_values($files)]);

            return $this->ready($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan);
        }

        if (($data['artifactId'] ?? '') !== 'sourceSize' || ($data['status'] ?? '') !== 'success') {
            return $deployment;
        }

        // A stat artifact reports the file's byte size as its 'content'.
        $size = (int) ($data['content'] ?? 0);
        if ($size <= 0) {
            return $deployment;
        }

        return $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
            'sourceSize' => $size,
            'totalSize' => $size + (int) $deployment->getAttribute('buildSize', 0),
        ]));
    }

    /**
     * Failures short-circuit here — no output is needed to fail. A success
     * needs every terminal callback: exit carries the code but fires before
     * post-job artifacts, complete confirms delivery, and site builds also
     * need the manifest. Each leaves a cache marker and re-attempts the join
     * via ready(), so whichever lands last finalizes.
     */
    protected function onExit(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        int $exitCode,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        array $platform,
        array $plan,
    ): Document {
        if ($exitCode !== 0) {
            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, "Build failed with exit code {$exitCode}.", $usage, $publisherForUsage, $publisherForScreenshots, $vcsFactory, $platform);
        }

        $cache->save('jobs-exit-' . $deployment->getId(), true);

        return $this->ready($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan);
    }

    /**
     * The delivery half of the success join — see onExit. Fires once post-job
     * artifacts have run, so the output is already where Appwrite reads it.
     */
    protected function onComplete(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        array $platform,
        array $plan,
    ): Document {
        $cache->save('jobs-complete-' . $deployment->getId(), true);

        return $this->ready($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan);
    }

    /**
     * Attempt the success join (see onExit); a no-op until every marker is in.
     * Once joined: adapter detection for sites, size limit, then ready.
     */
    protected function ready(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        array $platform,
        array $plan,
    ): Document {
        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)) {
            return $deployment; // already finalized
        }

        $deploymentId = $deployment->getId();
        $isSite = $deployment->getAttribute('resourceType') === 'sites';

        if ($cache->load('jobs-exit-' . $deploymentId, self::DEDUPE_TTL) === false || $cache->load('jobs-complete-' . $deploymentId, self::DEDUPE_TTL) === false) {
            return $deployment;
        }

        $manifest = $isSite ? $cache->load('jobs-manifest-' . $deploymentId, self::DEDUPE_TTL) : null;
        if ($isSite && $manifest === false) {
            return $deployment;
        }

        if ($isSite) {
            [$deployment, $mismatch] = $this->detect($dbForProject, $deployment, (array) ($manifest['files'] ?? []));
            if ($mismatch !== null) {
                return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, $mismatch, $usage, $publisherForUsage, $publisherForScreenshots, $vcsFactory, $platform);
            }
        }

        $path = (string) $deployment->getAttribute('buildPath', '');
        if ($path === '' || ! $deviceForBuilds->exists($path)) {
            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build produced no output artifact.', $usage, $publisherForUsage, $publisherForScreenshots, $vcsFactory, $platform);
        }

        $size = $deviceForBuilds->getFileSize($path);

        $limit = isset($plan['buildSize'])
            ? (int) $plan['buildSize'] * 1000 * 1000
            : (int) System::getEnv('_APP_COMPUTE_BUILD_SIZE_LIMIT', '2000000000');
        if ($limit !== 0 && $size > $limit) {
            $deviceForBuilds->delete($path);

            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build size should be less than ' . \number_format($limit / (1000 * 1000), 2) . ' MBs.', $usage, $publisherForUsage, $publisherForScreenshots, $vcsFactory, $platform);
        }

        return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, true, '', $usage, $publisherForUsage, $publisherForScreenshots, $vcsFactory, $platform, $size);
    }

    /**
     * Adapter detection over the site's build-manifest file listing: a first
     * successful build pins adapter + fallbackFile on the site and deployment;
     * a site pinned to 'ssr' that built static returns the failure message.
     *
     * @return array{Document, ?string}
     */
    protected function detect(Database $dbForProject, Document $deployment, array $files): array
    {
        $site = empty($files) ? new Document() : $dbForProject->getDocument('sites', $deployment->getAttribute('resourceId'));
        if ($site->isEmpty()) {
            return [$deployment, null];
        }

        $detection = Detection::rendering($site->getAttribute('framework', ''), $files);

        $adapter = $site->getAttribute('adapter', '');
        if (empty($adapter)) {
            $update = [
                'adapter' => $detection->getName(),
                'fallbackFile' => $detection->getFallbackFile() ?? '',
            ];
            $dbForProject->updateDocument('sites', $site->getId(), new Document($update));

            return [$dbForProject->updateDocument('deployments', $deployment->getId(), new Document($update)), null];
        }

        if ($adapter === 'ssr' && $detection->getName() === 'static') {
            return [$deployment, 'Adapter mismatch. Detected: ' . $detection->getName() . ' does not match with the set adapter: ' . $adapter];
        }

        return [$deployment, null];
    }

    /**
     * Apply a terminal outcome (ready or failed) to the deployment. Owns
     * activation, usage and the latestDeployment pointer, mirroring the executor
     * Builds worker.
     */
    protected function finalize(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        bool $success,
        string $message,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        VcsFactory $vcsFactory,
        array $platform,
        int $buildSize = 0,
    ): Document {
        $collection = $deployment->getAttribute('resourceType', 'functions');
        $resource = $dbForProject->getDocument($collection, $deployment->getAttribute('resourceId'));

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

        if ($applied > 0 && $success && $deployment->getAttribute('activate') === true && ! $resource->isEmpty()) {
            $this->activate($dbForProject, $dbForPlatform, $project, $resource, $deployment);
        }

        if ($applied > 0 && $success && $collection === 'sites' && ! $resource->isEmpty()) {
            // Every successful site build, activated or not, repoints the
            // branch preview rule and refreshes the console screenshots.
            Base::activateBranchPreviewRule($project, $resource, $deployment, $dbForPlatform, $platform['sitesDomain']);
            $publisherForScreenshots->enqueue(new \Appwrite\Event\Message\Screenshot(
                project: $project,
                deploymentId: $deployment->getId(),
            ));
        }

        // Count the build for usage/billing once it reached a terminal outcome
        // (mirrors the executor Builds worker); never for a concurrently-canceled
        // build (the guard above left it 'canceled').
        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true) && ! $resource->isEmpty()) {
            BuildUsage::publish($usage, $resource, $deployment, $project, $publisherForUsage);
        }

        // Keep the resource's "latest deployment" pointer + status current, and
        // (re)activate its schedule so the scheduler enqueues cron executions
        // (sites have no scheduleId, so schedule() no-ops for them).
        if (! $resource->isEmpty()) {
            $this->updateLatestDeployment($dbForProject, $resource);
            $this->schedule($dbForProject, $dbForPlatform, $resource);
        }

        $status = $deployment->getAttribute('status');
        if (\in_array($status, ['ready', 'failed'], true) && ! $resource->isEmpty()) {
            $this->gitAction($status, $deployment, $project, $dbForProject, $dbForPlatform, $vcsFactory, $platform);
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
     * Report a build state to the VCS provider for a VCS deployment
     * (best-effort; no-op for non-VCS builds).
     */
    protected function gitAction(string $status, Document $deployment, Document $project, Database $dbForProject, Database $dbForPlatform, VcsFactory $vcsFactory, array $platform): void
    {
        if ($deployment->getAttribute('providerCommitHash', '') === '' && $deployment->getAttribute('providerCommentId', '') === '') {
            return;
        }

        try {
            $resource = $dbForProject->getDocument($deployment->getAttribute('resourceType', 'functions'), $deployment->getAttribute('resourceId'));
            $installation = $dbForPlatform->getDocument('installations', $resource->getAttribute('installationId', ''));
            if ($resource->isEmpty() || $installation->getAttribute('providerInstallationId', '') === '') {
                return;
            }

            GitAction::run(
                $status,
                $vcsFactory->fromInstallation($installation),
                $deployment->getAttribute('providerCommitHash', ''),
                $deployment->getAttribute('providerRepositoryOwner', ''),
                $deployment->getAttribute('providerRepositoryName', ''),
                $project,
                $resource,
                $deployment,
                $dbForPlatform,
                $platform,
            );
        } catch (\Throwable) {
            // Best-effort — never fails the build.
        }
    }

    /**
     * Point the resource at this deployment (auto-activate). Mirrors the
     * essential activation from the Builds worker.
     */
    protected function activate(Database $dbForProject, Database $dbForPlatform, Document $project, Document $resource, Document $deployment): void
    {
        $resource = $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), new Document([
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
            Query::equal('deploymentResourceInternalId', [$resource->getSequence()]),
            Query::equal('deploymentResourceType', [$resource->getCollection() === 'sites' ? 'site' : 'function']),
            Query::equal('trigger', ['manual']),
            Query::equal('deploymentVcsProviderBranch', ['']),
        ]);
    }

    /**
     * Refresh the resource's latestDeployment* fields from its newest
     * deployment. Mirrors the Builds worker so the console reflects the current
     * build status.
     */
    protected function updateLatestDeployment(Database $dbForProject, Document $resource): void
    {
        $latest = $dbForProject->findOne('deployments', [
            Query::equal('resourceType', [$resource->getCollection()]),
            Query::equal('resourceInternalId', [$resource->getSequence()]),
            Query::orderDesc('$createdAt'),
        ]);

        if ($latest->isEmpty()) {
            return;
        }

        $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), new Document([
            'latestDeploymentId' => $latest->getId(),
            'latestDeploymentInternalId' => $latest->getSequence(),
            'latestDeploymentCreatedAt' => $latest->getCreatedAt(),
            'latestDeploymentStatus' => $latest->getAttribute('status', ''),
        ]));
    }

    /**
     * (Re)activate the resource's schedule document so the scheduler enqueues
     * cron executions. Mirrors the executor Builds worker: a schedule is active
     * only when the resource has both a cron expression and an active
     * deployment. Re-reads the resource so it sees a deploymentId just set by
     * activate().
     */
    protected function schedule(Database $dbForProject, Database $dbForPlatform, Document $resource): void
    {
        $scheduleId = $resource->getAttribute('scheduleId', '');
        if ($scheduleId === '') {
            return;
        }

        $resource = $dbForProject->getDocument($resource->getCollection(), $resource->getId());
        $schedule = $dbForPlatform->getDocument('schedules', $scheduleId);
        if ($schedule->isEmpty()) {
            return;
        }

        $dbForPlatform->updateDocument('schedules', $schedule->getId(), new Document([
            'resourceUpdatedAt' => DateTime::now(),
            'schedule' => $resource->getAttribute('schedule', ''),
            'active' => ! empty($resource->getAttribute('schedule')) && ! empty($resource->getAttribute('deploymentId')),
        ]));
    }

    /**
     * Notify project webhooks and event-triggered functions of a deployment
     * update, mirroring the executor Builds worker's fan-out. queueForEvents
     * only builds the event; realtime is triggered separately in action().
     */
    protected function dispatchUpdate(
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Document $project,
        Document $deployment,
    ): void {
        $model = new Deployment();
        $update = $queueForEvents
            ->setProject($project)
            ->setEvent(self::event($deployment))
            ->setParam(self::resourceParam($deployment), $deployment->getAttribute('resourceId'))
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
            envelopeId: $update->getEnvelopeId(),
        ));
    }

    private static function event(Document $deployment): string
    {
        $param = self::resourceParam($deployment);

        return "{$deployment->getAttribute('resourceType', 'functions')}.[{$param}].deployments.[deploymentId].update";
    }

    private static function resourceParam(Document $deployment): string
    {
        return $deployment->getAttribute('resourceType') === 'sites' ? 'siteId' : 'functionId';
    }

    protected function truncate(string $logs): string
    {
        $limit = APP_LOG_LENGTH_LIMIT;
        if (\strlen($logs) <= $limit) {
            return $logs;
        }

        return \substr($logs, -$limit);
    }
}
