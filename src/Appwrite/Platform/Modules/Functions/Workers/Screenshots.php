<?php

namespace Appwrite\Platform\Modules\Functions\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Realtime;
use Appwrite\Permission;
use Appwrite\Role;
use Exception;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Fetch\Client as FetchClient;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Storage\Device;
use Utopia\System\System;

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
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Realtime $queueForRealtime,
        Database $dbForPlatform,
        Database $dbForProject,
        Document $project,
        Device $deviceForFiles
    ): void {
        Console::log('Screenshot action started');

        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        Console::log('Site screenshot started');

        $deploymentId = $payload['deploymentId'] ?? null;
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new \Exception('Deployment not found');
        }

        $siteId = $deployment->getAttribute('resourceId');
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new \Exception('Site not found');
        }

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

            $client = new FetchClient();
            $client->setTimeout(\intval($site->getAttribute('timeout', '15')) * 1000);
            $client->addHeader('content-type', FetchClient::CONTENT_TYPE_APPLICATION_JSON);

            $bucket = $dbForPlatform->getDocument('buckets', 'screenshots');

            if ($bucket->isEmpty()) {
                throw new \Exception('Bucket not found');
            }

            $routerHost = System::getEnv('_APP_WORKER_SCREENSHOTS_ROUTER', 'http://appwrite');
            $configs = [
                'screenshotLight' => [
                    'headers' => [ 'x-appwrite-hostname' => $rule->getAttribute('domain') ],
                    'url' => $routerHost . '/?appwrite-preview=1&appwrite-theme=light',
                    'theme' => 'light'
                ],
                'screenshotDark' => [
                    'headers' => [ 'x-appwrite-hostname' => $rule->getAttribute('domain') ],
                    'url' => $routerHost . '/?appwrite-preview=1&appwrite-theme=dark',
                    'theme' => 'dark'
                ],
            ];

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

            $screenshotError = null;
            $screenshots = batch(\array_map(function ($key) use ($configs, $apiKey, $site, $client, &$screenshotError) {
                return function () use ($key, $configs, $apiKey, $site, $client, &$screenshotError) {
                    try {
                        $config = $configs[$key];

                        $config['headers'] = \array_merge($config['headers'] ?? [], [
                            'x-appwrite-key' => API_KEY_DYNAMIC . '_' . $apiKey
                        ]);
                        $config['sleep'] = 3000;

                        $frameworks = Config::getParam('frameworks', []);
                        $framework = $frameworks[$site->getAttribute('framework', '')] ?? null;
                        if (!is_null($framework)) {
                            $config['sleep'] = $framework['screenshotSleep'];
                        }

                        $browserEndpoint = System::getEnv('_APP_BROWSER_HOST', 'http://appwrite-browser:3000/v1');
                        $fetchResponse = $client->fetch(
                            url: $browserEndpoint . '/screenshots',
                            method: 'POST',
                            body: $config
                        );

                        if ($fetchResponse->getStatusCode() >= 400) {
                            throw new \Exception($fetchResponse->getBody());
                        }

                        $screenshot = $fetchResponse->getBody();

                        return ['key' => $key, 'screenshot' => $screenshot];
                    } catch (\Throwable $th) {
                        $screenshotError = $th->getMessage();
                        return;
                    }
                };
            }, \array_keys($configs)));

            if (!\is_null($screenshotError)) {
                throw new \Exception($screenshotError);
            }

            $mimeType = "image/png";
            $updates = new Document([]);

            foreach ($screenshots as $data) {
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
            Console::warning("Screenshot failed to generate:");
            Console::warning($th->getMessage());
            Console::warning($th->getTraceAsString());

            $date = \date('H:i:s');
            $this->appendToLogs($dbForProject, $deployment->getId(), $queueForRealtime, "[90m[$date] [90m[[0mappwrite[90m][33m Screenshot capturing failed. Deployment will continue. [0m\n");

            throw $th;
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
