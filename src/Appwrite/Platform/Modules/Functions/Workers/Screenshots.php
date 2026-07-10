<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Message\Screenshot;
use Appwrite\Event\Realtime;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\Screenshots\Client as ScreenshotsClient;
use Exception;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;

use function Swoole\Coroutine\batch;

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
        ScreenshotsClient $screenshots
    ): void {
        Span::add('project.id', $project->getId());

        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $screenshotMessage = Screenshot::fromArray($payload);
        $counter = $telemetry->createCounter('worker.screenshots.capture');

        $deploymentId = $screenshotMessage->deploymentId;
        Span::add('deployment.id', $deploymentId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new \Exception('Deployment not found');
        }

        $siteId = $deployment->getAttribute('resourceId');
        Span::add('site.id', $siteId);
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new \Exception('Site not found');
        }

        Span::add('site.framework', $site->getAttribute('framework', ''));

        // Realtime preparation
        $event = "sites.[siteId].deployments.[deploymentId].update";
        $queueForRealtime
            ->setSubscribers(['console'])
            ->setProject($project)
            ->setEvent($event)
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $date = \date('H:i:s');
        $this->appendToLogs($dbForProject, $deployment->getId(), $queueForRealtime, "[90m[$date] [90m[[0mappwrite[90m][97m Screenshot capturing started. [0m\n");

        try {
            $rule = $dbForPlatform->findOne('rules', [
                Query::equal("projectInternalId", [$project->getSequence()]),
                Query::equal("type", ["deployment"]),
                Query::equal('deploymentInternalId', [$deployment->getSequence()]),
            ]);

            if ($rule->isEmpty()) {
                throw new \Exception("Rule for deployment not found");
            }

            $bucket = $dbForPlatform->getDocument('buckets', 'screenshots');

            if ($bucket->isEmpty()) {
                throw new \Exception('Bucket not found');
            }

            $routerHost = System::getEnv('_APP_WORKER_SCREENSHOTS_ROUTER', '');
            if (empty($routerHost)) {
                $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
                $routerHost = "$protocol://{$rule->getAttribute('domain')}";
            }

            $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0);
            $apiKey = $jwtObj->encode([
                'hostnameOverride' => true,
                'disabledMetrics' => [
                    METRIC_EXECUTIONS,
                    METRIC_EXECUTIONS_COMPUTE,
                    METRIC_EXECUTIONS_MB_SECONDS,
                    METRIC_NETWORK_REQUESTS,
                    METRIC_NETWORK_INBOUND,
                    METRIC_NETWORK_OUTBOUND,
                    str_replace(["{resourceType}"], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS),
                    str_replace(["{resourceType}"], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE),
                    str_replace(["{resourceType}"], [RESOURCE_TYPE_SITES], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS),
                    str_replace(["{resourceType}", "{resourceInternalId}"], [RESOURCE_TYPE_SITES, $site->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS),
                    str_replace(["{resourceType}", "{resourceInternalId}"], [RESOURCE_TYPE_SITES, $site->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE),
                    str_replace(["{resourceType}", "{resourceInternalId}"], [RESOURCE_TYPE_SITES, $site->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS),
                ],
                'bannerDisabled' => true,
                'projectCheckDisabled' => true,
                'previewAuthDisabled' => true,
                'deploymentStatusIgnored' => true
            ]);

            $headers = [
                'x-appwrite-hostname' => $rule->getAttribute('domain'),
                'x-appwrite-key' => API_KEY_EPHEMERAL . '_' . $apiKey,
            ];

            $framework = Config::getParam('frameworks', [])[$site->getAttribute('framework', '')] ?? null;
            $sleep = $framework['screenshotSleep'] ?? 3000;

            $themes = ['screenshotLight' => 'light', 'screenshotDark' => 'dark'];

            $screenshotError = null;
            $captures = batch(\array_map(function ($key) use ($themes, $screenshots, $routerHost, $headers, $sleep, &$screenshotError) {
                return function () use ($key, $themes, $screenshots, $routerHost, $headers, $sleep, &$screenshotError) {
                    try {
                        $theme = $themes[$key];
                        $screenshot = $screenshots->create(
                            url: $routerHost . '/?appwrite-preview=1&appwrite-theme=' . $theme,
                            theme: $theme,
                            headers: $headers,
                            sleep: $sleep,
                        );

                        return ['key' => $key, 'screenshot' => $screenshot];
                    } catch (\Throwable $th) {
                        $screenshotError = $th->getMessage();
                        return;
                    }
                };
            }, \array_keys($themes)));

            if (!\is_null($screenshotError)) {
                throw new \Exception($screenshotError);
            }

            Span::add('screenshot.count', \count($captures));

            $mimeType = "image/png";
            $updates = new Document([]);

            foreach ($captures as $data) {
                $key = $data['key'];
                $screenshot = $data['screenshot'];

                $fileId = ID::unique();
                $fileName = $fileId . '.png';
                $path = $deviceForFiles->getPath($fileName);
                $path = str_ireplace($deviceForFiles->getRoot(), $deviceForFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path); // Add bucket id to path after root
                $success = $deviceForFiles->write($path, $screenshot, $mimeType);

                if (!$success) {
                    throw new \Exception("Screenshot failed to save");
                }

                $teamId = $project->getAttribute('teamId', '');
                $file = new Document([
                    '$id' => $fileId,
                    '$permissions' => [
                        Permission::read(Role::team(ID::custom($teamId))),
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

            $queueForRealtime
                ->setPayload($deployment->getArrayCopy())
                ->trigger();

            $site = $dbForProject->updateDocument('sites', $site->getId(), new Document([
                'deploymentScreenshotDark' => $deployment->getAttribute('screenshotDark', ''),
                'deploymentScreenshotLight' => $deployment->getAttribute('screenshotLight', ''),
            ]));
        } catch (\Throwable $th) {
            $date = \date('H:i:s');
            $this->appendToLogs($dbForProject, $deployment->getId(), $queueForRealtime, "[90m[$date] [90m[[0mappwrite[90m][33m Screenshot capturing failed. Deployment will continue. [0m\n");

            $this->recordTelemetry($counter, 'failure');

            throw $th;
        }

        $this->recordTelemetry($counter, 'success');
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

    protected function appendToLogs(Database $dbForProject, string $deploymentId, Realtime $queueForRealtime, string $logs)
    {
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        $buildLogs = $deployment->getAttribute('buildLogs', '');
        $buildLogs .= $logs;

        $deployment = $dbForProject->updateDocument('deployments', $deployment->getId(), new Document([
            'buildLogs' => $buildLogs
        ]));

        $queueForRealtime
            ->setPayload($deployment->getArrayCopy())
            ->trigger();
    }
}
