<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Key;
use Appwrite\Event\Message\Screenshot;
use Appwrite\Event\Realtime;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\Screenshots\Access;
use Appwrite\Screenshots\Client;
use Appwrite\Screenshots\Exception as ScreenshotsException;
use Exception;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;

class Screenshots extends Action
{
    public static function getName(): string
    {
        return 'screenshots';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Screenshots worker')
            ->groups(['screenshots'])
            ->inject('message')
            ->inject('queueForRealtime')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('deviceForFiles')
            ->inject('telemetry')
            ->inject('screenshots')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Realtime $queueForRealtime,
        Database $dbForPlatform,
        Database $dbForProject,
        Document $project,
        Device $deviceForFiles,
        Telemetry $telemetry,
        Client $screenshots
    ): void {
        Span::add('project.id', $project->getId());

        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $screenshotMessage = Screenshot::fromArray($payload);
        $counter = $telemetry->createCounter('worker.screenshots.capture');
        $duration = $telemetry->createHistogram('worker.screenshots.capture.duration', 's');

        $deploymentId = $screenshotMessage->deploymentId;
        Span::add('deployment.id', $deploymentId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        // Deleted since the build queued the capture: there is nothing left to
        // capture or to attach the screenshots to, on this attempt or any other.
        if ($deployment->isEmpty()) {
            $this->abandon($project, $deploymentId, 'Deployment not found');

            return;
        }

        $siteId = $deployment->getAttribute('resourceId');
        Span::add('site.id', $siteId);
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            $this->abandon($project, $deploymentId, 'Site not found');

            return;
        }

        Span::add('site.framework', $site->getAttribute('framework', ''));

        // An earlier attempt stored the screenshots and failed after: finish its last steps instead of capturing again
        if ($deployment->getAttribute('screenshotLight', '') !== '' && $deployment->getAttribute('screenshotDark', '') !== '') {
            $this->resume($message, $dbForPlatform, $dbForProject, $project, $site, $deployment);

            return;
        }

        // Realtime preparation
        $event = "sites.[siteId].deployments.[deploymentId].update";
        $queueForRealtime
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent($event)
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $bucket = new Document();
        $files = [];

        try {
            $rule = $dbForPlatform->findOne('rules', [
                Query::equal("projectInternalId", [$project->getSequence()]),
                Query::equal("type", ["deployment"]),
                Query::equal('deploymentInternalId', [$deployment->getSequence()]),
                Query::orderAsc('$sequence'),
            ]);

            if ($rule->isEmpty()) {
                throw new PermanentFailure('Rule for deployment not found');
            }

            Span::add('rule.domain', $rule->getAttribute('domain', ''));
            Span::add('rule.trigger', $rule->getAttribute('trigger', ''));

            $bucket = $dbForPlatform->getDocument('buckets', 'screenshots');

            if ($bucket->isEmpty()) {
                throw new PermanentFailure('Bucket not found');
            }

            $routerHost = System::getEnv('_APP_WORKER_SCREENSHOTS_ROUTER', '');
            if (empty($routerHost)) {
                $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
                $routerHost = "$protocol://{$rule->getAttribute('domain')}";
            }

            $headers = $this->getHeaders($project, $rule);

            $framework = Config::getParam('frameworks', [])[$site->getAttribute('framework', '')] ?? null;
            $sleep = $framework['screenshotSleep'] ?? 3000;

            $date = \date('H:i:s');
            $this->appendToLogs($dbForProject, $deployment->getId(), $queueForRealtime, "[90m[$date] [90m[[0mappwrite[90m][97m Screenshot capturing started. [0m\n");

            $captures = $this->captureThemes(
                $screenshots,
                $duration,
                $routerHost . '/',
                $headers,
                $sleep,
            );

            Span::add('screenshot.count', \count($captures));

            if ($dbForProject->getDocument('deployments', $deployment->getId())->isEmpty()) {
                throw new PermanentFailure('Deployment deleted during capture');
            }

            $mimeType = "image/png";
            $updates = new Document([]);


            foreach ($captures as $key => $screenshot) {
                $fileId = Id::unique();
                $fileName = $fileId . '.png';
                $path = $deviceForFiles->getPath($fileName);
                $path = str_ireplace($deviceForFiles->getRoot(), $deviceForFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path); // Add bucket id to path after root
                $success = $deviceForFiles->write($path, new Stream($screenshot), $mimeType);
                $files[$fileId] = $path;

                if (!$success) {
                    throw new \Exception("Screenshot failed to save");
                }

                $teamId = $project->getAttribute('teamId', '');
                $file = new Document([
                    '$id' => $fileId,
                    '$permissions' => [
                        Permission::read(Role::team(Id::custom($teamId))),
                    ],
                    'bucketId' => $bucket->getId(),
                    'bucketInternalId' => $bucket->getSequence(),
                    'name' => $fileName,
                    'path' => $path,
                    'signature' => $deviceForFiles->getFileHash($path),
                    'mimeType' => $mimeType,
                    'sizeOriginal' => \strlen($screenshot),
                    'sizeActual' => $deviceForFiles->getFileSize($path),
                    'algorithm' => Compression::NONE,
                    'comment' => '',
                    'chunksTotal' => 1,
                    'chunksUploaded' => 1,
                    'openSSLVersion' => null,
                    'openSSLCipher' => null,
                    'openSSLTag' => null,
                    'openSSLIV' => null,
                    'search' => implode(' ', [$fileId, $fileName]),
                    'metadata' => ['content_type' => $mimeType],
                ]);

                $dbForPlatform->createDocument('bucket_' . $bucket->getSequence(), $file);

                $updates->setAttribute($key, $fileId);
            }

            $date = \date('H:i:s');
            $this->appendToLogs($dbForProject, $deployment->getId(), $queueForRealtime, "[90m[$date] [90m[[0mappwrite[90m][97m Screenshot capturing finished. [0m\n");

            // Apply screenshot properties
            $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), $updates);

            // Deleted while the pages were captured: the update found no row,
            // and the site must not take an empty preview from it.
            if ($deployment->isEmpty()) {
                throw new PermanentFailure('Deployment deleted during capture');
            }

            // The deployment owns them now, and the Deletes worker removes them with it.
            $captured = $files;
            $files = [];

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            $site = $dbForProject->updateDocument('sites', $site->getId(), new Document([
                'deploymentScreenshotDark' => $deployment->getAttribute('screenshotDark', ''),
                'deploymentScreenshotLight' => $deployment->getAttribute('screenshotLight', ''),
            ]));

            // Unless the site is gone: its Deletes worker may have read this deployment before the update above.
            if ($site->isEmpty()) {
                $files = $captured;
                throw new PermanentFailure('Site deleted during capture');
            }

            // Filed under the team on the queued message, which a move since the build may have left. A failure
            // retries the job, which finds the screenshots stored and only grants them.
            (new Access($dbForPlatform))->grantDeployment($dbForProject, $project, $deployment->getId());
        } catch (\Throwable $th) {
            $date = \date('H:i:s');
            $reason = $th->getMessage() === '' ? \get_class($th) : $th->getMessage();
            $this->appendToLogs($dbForProject, $deployment->getId(), $queueForRealtime, "[90m[$date] [90m[[0mappwrite[90m][33m Screenshot capturing failed. Deployment will continue. Reason: {$reason} [0m\n");

            $this->recordTelemetry($counter, 'failure');

            // Nothing references a screenshot the deployment did not take, and a retry captures new ones.
            $this->deleteScreenshots($dbForPlatform, $deviceForFiles, $bucket, $files);

            // Best-effort: a permanent failure, or the last attempt (NATS only), is acknowledged, not dead-lettered.
            $unroutable = $th instanceof ScreenshotsException && \str_contains($th->getMessage(), 'is not publicly routable');
            if ($th instanceof PermanentFailure || $unroutable || $message->isLastAttempt()) {
                $this->abandon($project, $deployment->getId(), $th->getMessage());

                return;
            }

            throw $th;
        }

        $this->recordTelemetry($counter, 'success');
    }

    /**
     * Point the site at the stored screenshots and grant them to the project's team, as a capture ends.
     */
    protected function resume(Message $message, Database $dbForPlatform, Database $dbForProject, Document $project, Document $site, Document $deployment): void
    {
        Span::add('screenshot.resumed', true);

        try {
            $dbForProject->updateDocument('sites', $site->getId(), new Document([
                'deploymentScreenshotDark' => $deployment->getAttribute('screenshotDark', ''),
                'deploymentScreenshotLight' => $deployment->getAttribute('screenshotLight', ''),
            ]));

            (new Access($dbForPlatform))->grantDeployment($dbForProject, $project, $deployment->getId());
        } catch (\Throwable $th) {
            if ($message->isLastAttempt()) {
                $this->abandon($project, $deployment->getId(), $th->getMessage());

                return;
            }

            throw $th;
        }
    }

    /**
     * Request headers sent with the screenshot capture, authorizing the router to serve the rule's deployment.
     *
     * @return array<string, string>
     */
    protected function getHeaders(Document $project, Document $rule): array
    {
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0);
        $apiKey = $jwt->encode([
            Key::PURPOSE_CLAIM => Key::PURPOSE,
            'hostname' => $rule->getAttribute('domain'),
            'deploymentId' => $rule->getAttribute('deploymentId'),
            'hostnameOverride' => true,
            'disabledMetrics' => [
                METRIC_EXECUTIONS,
                METRIC_EXECUTIONS_COMPUTE,
                METRIC_EXECUTIONS_MB_SECONDS,
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
                str_replace(['{resourceType}'], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS),
                str_replace(['{resourceType}'], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE),
                str_replace(['{resourceType}'], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS),
            ],
            'bannerDisabled' => true,
            'projectCheckDisabled' => true,
            'previewAuthDisabled' => true,
            'deploymentStatusIgnored' => true,
            'runtimeEphemeral' => true,
        ]);

        return [
            'x-appwrite-hostname' => $rule->getAttribute('domain'),
            'x-appwrite-key' => API_KEY_EPHEMERAL . '_' . $apiKey,
        ];
    }

    protected function abandon(Document $project, string $deploymentId, string $reason): void
    {
        Span::add('screenshot.status', 'failed');
        Span::add('screenshot.reason', $reason);

        Console::warning('Screenshot abandoned: project=' . $project->getId() . ' deployment=' . $deploymentId . ' reason=' . $reason);
    }

    /**
     * @param array<string, string> $files file ID => path
     */
    protected function deleteScreenshots(Database $dbForPlatform, Device $deviceForFiles, Document $bucket, array $files): void
    {
        foreach ($files as $fileId => $path) {
            try {
                // Keep the row while the object is still there, so it stays findable.
                if (!$deviceForFiles->delete($path) && $deviceForFiles->exists($path)) {
                    Console::warning('Failed to delete screenshot ' . $fileId . ': ' . $path);
                    continue;
                }

                $dbForPlatform->deleteDocument('bucket_' . $bucket->getSequence(), $fileId);
            } catch (\Throwable $th) {
                Console::warning('Failed to delete screenshot ' . $fileId . ': ' . $th->getMessage());
            }
        }
    }

    protected function recordTelemetry(Counter $counter, string $result): void
    {
        try {
            $counter->add(1, [
                'resourceType' => RESOURCE_TYPE_SITES,
                'result' => $result,
            ]);
        } catch (\Throwable) {
            // Telemetry should never affect screenshot processing.
        }
    }

    protected function recordDuration(Histogram $duration, float $seconds, string $theme, string $result): void
    {
        try {
            $duration->record($seconds, [
                'resourceType' => RESOURCE_TYPE_SITES,
                'theme' => $theme,
                'result' => $result,
            ]);
        } catch (\Throwable) {
            // Telemetry should never affect screenshot processing.
        }
    }

    protected function appendToLogs(Database $dbForProject, string $deploymentId, Realtime $queueForRealtime, string $logs)
    {
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        // A deployment deleted mid-capture has no logs left to append to.
        if ($deployment->isEmpty()) {
            return;
        }

        $buildLogs = $deployment->getAttribute('buildLogs', '');
        $buildLogs .= $logs;

        $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
            'buildLogs' => $buildLogs
        ]));

        $queueForRealtime
            ->setPayload($deployment->getArrayCopy())
            ->trigger();
    }

    /**
     * Capture light and dark previews. In the Swoole worker both themes are
     * requested together so a job stays within one HTTP timeout budget; outside
     * a coroutine (unit tests) they run sequentially.
     *
     * @param array<string, string> $headers
     * @return array<string, string> Keys `screenshotLight` and `screenshotDark`
     */
    protected function captureThemes(Client $screenshots, Histogram $duration, string $url, array $headers, int $sleep): array
    {
        $themes = ['screenshotLight' => 'light', 'screenshotDark' => 'dark'];

        if (Coroutine::getCid() === -1) {
            $captures = [];
            foreach ($themes as $key => $theme) {
                $captures[$key] = $this->capture($screenshots, $duration, $url, $theme, $headers, $sleep);
            }

            return $captures;
        }

        $channel = new Channel(\count($themes));
        $captures = [];
        $errors = [];

        foreach ($themes as $key => $theme) {
            \go(function () use ($key, $theme, $screenshots, $duration, $url, $headers, $sleep, $channel, &$captures, &$errors) {
                try {
                    $captures[$key] = $this->capture($screenshots, $duration, $url, $theme, $headers, $sleep);
                } catch (\Throwable $throwable) {
                    $errors[$key] = $throwable;
                } finally {
                    $channel->push(true);
                }
            });
        }

        for ($i = 0, $count = \count($themes); $i < $count; $i++) {
            $channel->pop();
        }
        $channel->close();

        if ($errors !== []) {
            throw \reset($errors);
        }

        return $captures;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function capture(Client $screenshots, Histogram $duration, string $url, string $theme, array $headers, int $sleep): string
    {
        $start = \microtime(true);
        $result = 'failure';

        try {
            $capture = $screenshots->create($url, $theme, $headers, $sleep);
            $result = 'success';

            return $capture;
        } finally {
            $this->recordDuration($duration, \microtime(true) - $start, $theme, $result);
        }
    }
}
