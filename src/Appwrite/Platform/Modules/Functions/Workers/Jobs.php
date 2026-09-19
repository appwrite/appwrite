<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Appwrite\Bus\Events\RuleUpdated;
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
use OpenRuntimes\Orchestrator\Callback\JobArtifact;
use OpenRuntimes\Orchestrator\Callback\JobExit;
use OpenRuntimes\Orchestrator\Callback\JobLog;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use OpenRuntimes\Orchestrator\Enum\ErrorCode;
use Utopia\Bus\Bus;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\System\System;

/**
 * Applies open-runtimes jobs-service callbacks to a deployment. The API
 * callback endpoint verifies + enqueues the CloudEvent; this worker owns the
 * deployment state transitions: log streaming and the ready/failed outcome.
 *
 * Callbacks arrive concurrently and out of order (one log line each), so
 * processing for a deployment is serialized under a per-deployment lock — that
 * keeps its buildLogs a clean, monotonic append while letting different
 * deployments build in parallel. Callbacks are at-least-once, so events are
 * de-duplicated on the CloudEvent id (inside the lock, so dedup + apply is
 * atomic and a lock timeout retries cleanly).
 *
 * Handlers are protected extension points: downstream workers (e.g. cloud)
 * override finalize() and wrap parent:: for post-activation work.
 *
 * A verified build runs beforeFinalize() outside the deployment lock, then
 * re-reads and finalizes under the lock. The original queue message owns all
 * three phases, so redelivery can resume an unfinished finalization.
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
            ->inject('bus')
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
        Bus $bus,
    ): void {
        $event = JobsMessage::fromArray($message->getPayload());

        $deploymentId = $event->data['meta']['deploymentId'] ?? '';
        $eventType = CallbackEvent::tryFrom($event->event);
        if ($deploymentId === '' || $eventType === null) {
            return;
        }

        $prepared = $locks('jobs-deployment:' . $deploymentId, self::LOCK_TTL, function () use ($event, $eventType, $project, $dbForProject, $dbForPlatform, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $publisherForScreenshots, $publisherForUsage, $usage, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan, $deploymentId, $bus): ?array {
            $deployment = $dbForProject->getDocument('deployments', $deploymentId);
            if ($deployment->isEmpty() || $deployment->getAttribute('status') === 'canceled') {
                return null;
            }

            $statusBefore = $deployment->getAttribute('status');
            $durationBefore = $deployment->getAttribute('buildDuration');
            $key = 'jobs-event-' . $event->id;
            if ($event->id === '' || $cache->load($key, self::DEDUPE_TTL) === false) {
                $deployment = match ($eventType) {
                    CallbackEvent::Log => $this->onLog($dbForProject, $dbForPlatform, $project, $deployment, JobLog::fromArray($event->data), $vcsFactory, $platform),
                    CallbackEvent::Artifact => $this->onArtifact($dbForProject, $dbForPlatform, $project, $deployment, JobArtifact::fromArray($event->data), $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan, $bus),
                    CallbackEvent::Exit => $this->onExit($dbForProject, $dbForPlatform, $project, $deployment, JobExit::fromArray($event->data), $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan, $bus),
                    CallbackEvent::Complete => $this->onComplete($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan, $bus),
                    default => $deployment,
                };
                $this->publishUpdate($dbForProject, $project, $deployment, $statusBefore, $durationBefore, $usage, $publisherForUsage, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
                if ($event->id !== '') {
                    $cache->save($key, true);
                }
            }

            // A duplicate still joins readiness: the prior attempt may have
            // applied its event and then failed during the outside-lock work.
            if ($eventType === CallbackEvent::Log) {
                return null;
            }
            $statusBefore = $deployment->getAttribute('status');
            $durationBefore = $deployment->getAttribute('buildDuration');
            [$deployment, $size] = $this->prepare($dbForProject, $dbForPlatform, $project, $deployment, $usage, $publisherForUsage, $publisherForScreenshots, $deviceForBuilds, $vcsFactory, $cache, $platform, $plan, $bus);
            if ($statusBefore !== $deployment->getAttribute('status')) {
                $this->publishUpdate($dbForProject, $project, $deployment, $statusBefore, $durationBefore, $usage, $publisherForUsage, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
            }

            return $size === null ? null : [$deployment, $size];
        }, self::LOCK_TIMEOUT);

        if ($prepared === null) {
            return;
        }
        [$deployment, $size] = $prepared;
        $logs = $this->beforeFinalize($dbForProject, $project, $deployment);

        $locks('jobs-deployment:' . $deploymentId, self::LOCK_TTL, function () use ($dbForProject, $dbForPlatform, $project, $deploymentId, $size, $logs, $publisherForScreenshots, $vcsFactory, $platform, $bus, $usage, $publisherForUsage, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions): void {
            $deployment = $dbForProject->getDocument('deployments', $deploymentId);
            if ($deployment->isEmpty() || \in_array($deployment->getAttribute('status'), ['ready', 'failed', 'canceled'], true)) {
                return;
            }

            $statusBefore = $deployment->getAttribute('status');
            $durationBefore = $deployment->getAttribute('buildDuration');
            $deployment->setAttribute('buildLogs', $deployment->getAttribute('buildLogs', '') . $logs);
            $deployment = $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, true, '', $publisherForScreenshots, $vcsFactory, $platform, $bus, $size);
            $this->publishUpdate($dbForProject, $project, $deployment, $statusBefore, $durationBefore, $usage, $publisherForUsage, $queueForRealtime, $queueForEvents, $queueForWebhooks, $publisherForFunctions);
        }, self::LOCK_TIMEOUT);
    }

    /** Run idempotent pre-finalization work without the deployment lock; return its logs. */
    protected function beforeFinalize(Database $dbForProject, Document $project, Document $deployment): string
    {
        return '';
    }

    /** Publish terminal transitions under the same lock that writes them. */
    private function publishUpdate(
        Database $dbForProject,
        Document $project,
        Document $deployment,
        string $statusBefore,
        ?int $durationBefore,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
    ): void {
        // Outcome and runtime arrive independently. Publish when the second
        // becomes known, once under the per-deployment callback lock.
        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)
            && $deployment->getAttribute('buildDuration') !== null
            && (!\in_array($statusBefore, ['ready', 'failed'], true) || $durationBefore === null)) {
            $resource = $dbForProject->getDocument($deployment->getAttribute('resourceType', 'functions'), $deployment->getAttribute('resourceId'));
            if (!$resource->isEmpty()) {
                BuildUsage::publish($usage, $resource, $deployment, $project, $publisherForUsage);
            }
        }

        // Console realtime on every callback (log stream + status).
        $queueForRealtime
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent(self::event($deployment))
            ->setParam(self::resourceParam($deployment), $deployment->getAttribute('resourceId'))
            ->setParam('deploymentId', $deployment->getId())
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
    }

    protected function onLog(Database $dbForProject, Database $dbForPlatform, Document $project, Document $deployment, JobLog $log, VcsFactory $vcsFactory, array $platform): Document
    {
        $chunk = \implode("\n", $log->lines);
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
     * listing for adapter detection; and 'output' confirms remote delivery.
     * Manifest and output callbacks save markers that join readiness.
     */
    protected function onArtifact(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        JobArtifact $artifact,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        array $platform,
        array $plan,
        Bus $bus,
    ): Document {
        $failed = $artifact->status === 'failed';
        if ($artifact->artifactId === 'manifest') {
            // A failed manifest degrades to an empty listing (detection
            // skipped), never a failed build.
            $manifest = $artifact->status === 'success' ? $artifact->content : null;
            $files = \is_array($manifest) ? (array) ($manifest['files'] ?? []) : [];
            $cache->save('jobs-manifest-' . $deployment->getId(), ['files' => \array_values($files)]);

            return $deployment;
        }

        // On a remote builds device the sidecar delivers the artifact. Join its
        // callback explicitly because complete is emitted after artifacts but
        // the queue can deliver those callbacks out of order.
        if ($artifact->artifactId === 'output') {
            if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)) {
                return $deployment;
            }
            if ($failed) {
                // Fail immediately even if exit delivery is lost. Leave duration
                // unknown until exit arrives, rather than billing callback wait.
                return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build output upload failed: ' . ($artifact->error->message ?? 'unknown error'), $publisherForScreenshots, $vcsFactory, $platform, $bus);
            }

            if ($artifact->status !== 'success') {
                return $deployment;
            }

            $cache->save('jobs-output-' . $deployment->getId(), true);

            return $deployment;
        }

        // Any other artifact failing dooms the build — the orchestrator aborts
        // the job on a pre-job failure, and a lost output has nothing to serve
        // — so fail it now with the artifact's own message (which file, which
        // status) rather than waiting for the bare exit code. The build cache
        // upload is the one best-effort artifact: losing it costs the next
        // build time, not this one.
        if ($failed && $artifact->artifactId !== 'cache') {
            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, $artifact->error->message ?? 'Build failed.', $publisherForScreenshots, $vcsFactory, $platform, $bus);
        }

        if ($artifact->artifactId !== 'sourceSize' || $artifact->status !== 'success') {
            return $deployment;
        }

        // A stat artifact reports the file's byte size as its 'content'.
        $size = (int) $artifact->content;
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
     * needs every terminal callback: exit carries the code, complete confirms
     * artifact processing, remote builds need successful output delivery, and
     * site builds also need the manifest. Each leaves a marker and retries the join
     * via ready(), so whichever lands last finalizes.
     */
    protected function onExit(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        JobExit $exit,
        UsageContext $usage,
        UsagePublisher $publisherForUsage,
        ScreenshotPublisher $publisherForScreenshots,
        Device $deviceForBuilds,
        VcsFactory $vcsFactory,
        Cache $cache,
        array $platform,
        array $plan,
        Bus $bus,
    ): Document {
        if ($deployment->getAttribute('status') === 'ready'
            || ($deployment->getAttribute('status') === 'failed' && $deployment->getAttribute('buildDuration') !== null)) {
            return $deployment;
        }

        // Only an exit records runtime. Artifact failures can seal the outcome
        // first; their later exit fills duration without repeating finalization.
        $duration = $exit->durationSeconds;
        $dbForProject->updateDocuments('deployments', new Document([
            'buildDuration' => $duration !== null && \is_finite($duration) && $duration >= 0
                ? (int) \ceil($duration)
                : $this->duration($deployment),
            'buildEndedAt' => $deployment->getAttribute('buildEndedAt') ?: DateTime::now(),
        ]), [
            Query::equal('$id', [$deployment->getId()]),
            Query::isNull('buildDuration'),
            Query::notEqual('status', 'canceled'),
            Query::notEqual('status', 'ready'),
        ]);
        $deployment = $dbForProject->getDocument('deployments', $deployment->getId());
        if (\in_array($deployment->getAttribute('status'), ['canceled', 'ready', 'failed'], true)) {
            return $deployment;
        }

        if ($exit->error !== null) {
            // The build command's own exit stays an exit code; anything else
            // (out of memory, failed before it could start) is explained.
            $message = $exit->error->code === ErrorCode::JobExitNonzero
                ? "Build failed with exit code {$exit->exitCode}."
                : $exit->error->message;

            return $this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, $message, $publisherForScreenshots, $vcsFactory, $platform, $bus);
        }

        $cache->save('jobs-exit-' . $deployment->getId(), true);

        return $deployment;
    }

    /**
     * The artifact-processing half of the success join — see onExit. It is
     * emitted after post-job artifacts run, but can be dequeued before their
     * callbacks, so remote output delivery has its own marker.
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
        Bus $bus,
    ): Document {
        $cache->save('jobs-complete-' . $deployment->getId(), true);

        return $deployment;
    }

    /**
     * Join success callbacks and validate the artifact before outside-lock work.
     * A null size means incomplete callbacks or an already sealed outcome.
     *
     * @return array{Document, ?int}
     */
    protected function prepare(
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
        Bus $bus,
    ): array {
        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)) {
            return [$deployment, null]; // already finalized
        }

        $deploymentId = $deployment->getId();
        $isSite = $deployment->getAttribute('resourceType') === 'sites';

        if ($cache->load('jobs-exit-' . $deploymentId, self::DEDUPE_TTL) === false || $cache->load('jobs-complete-' . $deploymentId, self::DEDUPE_TTL) === false) {
            return [$deployment, null];
        }

        if ($deviceForBuilds->getType() !== DeviceType::Local && $cache->load('jobs-output-' . $deploymentId, self::DEDUPE_TTL) === false) {
            return [$deployment, null];
        }

        $manifest = $isSite ? $cache->load('jobs-manifest-' . $deploymentId, self::DEDUPE_TTL) : null;
        if ($isSite && $manifest === false) {
            return [$deployment, null];
        }

        if ($isSite) {
            [$deployment, $mismatch] = $this->detect($dbForProject, $deployment, (array) ($manifest['files'] ?? []));
            if ($mismatch !== null) {
                return [$this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, $mismatch, $publisherForScreenshots, $vcsFactory, $platform, $bus), null];
            }
        }

        $path = (string) $deployment->getAttribute('buildPath', '');
        if ($path === '' || ! $deviceForBuilds->exists($path)) {
            return [$this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build produced no output artifact.', $publisherForScreenshots, $vcsFactory, $platform, $bus), null];
        }

        $size = $deviceForBuilds->getFileSize($path);

        $limit = isset($plan['buildSize'])
            ? (int) $plan['buildSize'] * 1000 * 1000
            : (int) System::getEnv('_APP_COMPUTE_BUILD_SIZE_LIMIT', '2000000000');
        if ($limit !== 0 && $size > $limit) {
            $deviceForBuilds->delete($path);

            return [$this->finalize($dbForProject, $dbForPlatform, $project, $deployment, false, 'Build size should be less than ' . \number_format($limit / (1000 * 1000), 2) . ' MBs.', $publisherForScreenshots, $vcsFactory, $platform, $bus), null];
        }

        return [$deployment, $size];
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
     * activation and the latestDeployment pointer, mirroring the executor
     * Builds worker.
     */
    protected function finalize(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $deployment,
        bool $success,
        string $message,
        ScreenshotPublisher $publisherForScreenshots,
        VcsFactory $vcsFactory,
        array $platform,
        Bus $bus,
        int $buildSize = 0,
    ): Document {
        // A build finalizes once. A failed artifact fails it with the
        // artifact's own message, and the exit that follows must not overwrite
        // that with a bare exit code. Late logs and metadata still land: only
        // the outcome is sealed. Sound under the per-deployment lock, which
        // serializes callbacks and re-reads the document for each.
        if (\in_array($deployment->getAttribute('status'), ['ready', 'failed'], true)) {
            return $deployment;
        }

        $collection = $deployment->getAttribute('resourceType', 'functions');
        $resource = $dbForProject->getDocument($collection, $deployment->getAttribute('resourceId'));
        $terminal = $success ? 'ready' : 'failed';

        $logs = $deployment->getAttribute('buildLogs', '');
        $trailer = $success
            ? "\033[90m[" . \date('H:i:s') . "] \033[90m[\033[0mappwrite\033[90m]\033[32m Deployment finished. \033[0m\n"
            : "\n" . ($message !== '' ? $message : 'Build failed.') . "\n";
        $update = [
            'buildEndedAt' => $deployment->getAttribute('buildEndedAt') ?: DateTime::now(),
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

        // latestDeployment* must be written before activate(). activate() sets
        // deploymentId then walks platform rules; under parallel Sites e2e that
        // scan is slow enough that clients waiting on deploymentId observe a
        // stale latestDeploymentId (still the previous deployment).
        if (! $resource->isEmpty()) {
            $this->updateLatestDeployment($dbForProject, $resource);
        }

        if ($applied > 0 && $success && $deployment->getAttribute('activate') === true && ! $resource->isEmpty()) {
            $this->activate($dbForProject, $dbForPlatform, $project, $resource, $deployment, $bus);
        }

        if ($applied > 0) {
            $applied = $dbForProject->updateDocuments('deployments', new Document(['status' => $terminal]), [
                Query::equal('$id', [$deployment->getId()]),
                Query::notEqual('status', 'canceled'),
            ]);
            $deployment = $dbForProject->getDocument('deployments', $deployment->getId());
        }

        if ($applied > 0 && ! $resource->isEmpty()) {
            $this->updateLatestDeployment($dbForProject, $resource);
        }

        if ($applied > 0 && $success && $collection === 'sites' && ! $resource->isEmpty()) {
            // Every successful site build, activated or not, repoints the
            // branch preview rule and refreshes the console screenshots.
            Base::activateBranchPreviewRule($project, $resource, $deployment, $dbForPlatform, $bus, $platform['sitesDomain']);
            $publisherForScreenshots->enqueue(new \Appwrite\Event\Message\Screenshot(
                project: $project,
                deploymentId: $deployment->getId(),
            ));
        }

        // (Re)activate its schedule so the scheduler enqueues cron executions
        // (sites have no scheduleId, so schedule() no-ops for them).
        if (! $resource->isEmpty()) {
            $this->schedule($dbForProject, $dbForPlatform, $resource);
        }

        $status = $deployment->getAttribute('status');
        if (\in_array($status, ['ready', 'failed'], true) && ! $resource->isEmpty()) {
            $this->gitAction($status, $deployment, $project, $dbForProject, $dbForPlatform, $vcsFactory, $platform);
        }

        return $deployment;
    }

    /**
     * Use the worker's measured duration once its exit callback has arrived.
     * Older exit callbacks without a measurement fall back to elapsed time.
     * Callbacks arrive out of order, so
     * buildStartedAt (stamped by the first log callback) can be missing when a
     * terminal callback finalizes first — fall back to the deployment's
     * creation time rather than reporting 0.
     */
    private function duration(Document $deployment): int
    {
        if (!empty($deployment->getAttribute('buildEndedAt')) && $deployment->getAttribute('buildDuration') !== null) {
            return (int) $deployment->getAttribute('buildDuration', 0);
        }

        $startedAt = $deployment->getAttribute('buildStartedAt', '') ?: $deployment->getCreatedAt();
        if (empty($startedAt)) {
            return 0;
        }

        try {
            $started = (float) (new \DateTimeImmutable($startedAt))->format('U.u');
            $ended = empty($deployment->getAttribute('buildEndedAt'))
                ? \microtime(true)
                : (float) (new \DateTimeImmutable($deployment->getAttribute('buildEndedAt')))->format('U.u');
        } catch (\Exception) {
            return 0;
        }

        // A timeout is a budget, not a measurement: termination grace can
        // legitimately leave the worker running past it.
        return (int) \ceil(\max(0.0, $ended - $started));
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
    protected function activate(Database $dbForProject, Database $dbForPlatform, Document $project, Document $resource, Document $deployment, Bus $bus): void
    {
        $branch = $deployment->getAttribute('providerBranch', '');
        $branches = $branch === '' ? [''] : ['', $branch];

        $dbForPlatform->forEach('rules', function (Document $rule) use ($dbForPlatform, $deployment, $bus) {
            $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                'deploymentId' => $deployment->getId(),
                'deploymentInternalId' => $deployment->getSequence(),
            ]));
            $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));
        }, [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('type', ['deployment']),
            Query::equal('deploymentResourceInternalId', [$resource->getSequence()]),
            Query::equal('deploymentResourceType', [$resource->getCollection() === 'sites' ? 'site' : 'function']),
            Query::equal('trigger', ['manual']),
            Query::equal('deploymentVcsProviderBranch', $branches),
        ]);

        $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), new Document([
            'live' => true,
            'deploymentId' => $deployment->getId(),
            'deploymentInternalId' => $deployment->getSequence(),
            'deploymentCreatedAt' => $deployment->getCreatedAt(),
        ]));
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
            Query::orderDesc('$sequence'),
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
        if (\strlen($logs) > $limit) {
            $logs = \substr($logs, -$limit);
        }

        // Build output can be binary, and the byte cut can split a multibyte character; MySQL rejects either.
        return \mb_scrub($logs, 'UTF-8');
    }
}
