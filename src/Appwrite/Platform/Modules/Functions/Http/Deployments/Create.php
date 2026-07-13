<?php

namespace Appwrite\Platform\Modules\Functions\Http\Deployments;

use Appwrite\Compute\Job;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Build as BuildMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Jobs;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/deployments')
            ->desc('Create deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('event', 'functions.[functionId].deployments.[deploymentId].create')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('usage.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'deployments',
                name: 'createDeployment',
                description: <<<EOT
                Create a new function code deployment. Use this endpoint to upload a new version of your code function. To execute your newly uploaded code, you'll need to update the function's deployment to use your new deployment UID.

                This endpoint accepts a tar.gz file compressed with your code. Make sure to include any dependencies your code has within the compressed file. You can learn more about code packaging in the [Appwrite Cloud Functions tutorial](https://appwrite.io/docs/functions).

                Use the "command" param to set the entrypoint used to execute your code.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ],
                requestType: ContentType::MULTIPART,
                type: MethodType::UPLOAD,
                packaging: true,
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new Nullable(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'Function ID.', false, ['dbForProject'])
            ->param('entrypoint', null, new Nullable(new Text(1028)), 'Entrypoint File.', true)
            ->param('commands', null, new Nullable(new Text(8192, 0)), 'Build Commands.', true)
            ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', skipValidation: true)
            ->param('activate', false, new Boolean(true), 'Automatically activate the deployment when it is finished building.')
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('deviceForFunctions')
            ->inject('deviceForLocal')
            ->inject('publisherForBuilds')
            ->inject('jobs')
            ->inject('plan')
            ->inject('authorization')
            ->inject('platform')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        ?string $entrypoint,
        ?string $commands,
        mixed $code,
        mixed $activate,
        Request $request,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Document $project,
        Device $deviceForFunctions,
        Device $deviceForLocal,
        BuildPublisher $publisherForBuilds,
        Jobs $jobs,
        array $plan,
        Authorization $authorization,
        array $platform,
        callable $locks
    ) {
        $activate = \strval($activate) === 'true' || \strval($activate) === '1';

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if ($entrypoint === null) {
            $entrypoint = $function->getAttribute('entrypoint', '');
        }

        if ($commands === null) {
            $commands = $function->getAttribute('commands', '');
        }

        if (empty($entrypoint)) {
            throw new Exception(Exception::FUNCTION_ENTRYPOINT_MISSING);
        }

        $file = $request->getFiles('code');

        // GraphQL multipart spec adds files with index keys
        if (empty($file)) {
            $file = $request->getFiles(0);
        }

        if (empty($file)) {
            throw new Exception(Exception::STORAGE_FILE_EMPTY, 'No file sent');
        }

        $functionSizeLimit = (int) System::getEnv('_APP_COMPUTE_SIZE_LIMIT', '30000000');

        if (isset($plan['deploymentSize'])) {
            $functionSizeLimit = $plan['deploymentSize'] * 1000 * 1000;
        }

        $fileExt = new FileExt([FileExt::TYPE_GZIP]);
        $fileSizeValidator = new FileSize($functionSizeLimit);
        $upload = new Upload();

        // Make sure we handle a single file and multiple files the same way
        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        if (!$fileExt->isValid($file['name'])) { // Check if file type is allowed
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
        }

        $contentRange = $request->getHeaderLine('content-range');
        $deploymentId = ID::unique();
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $deploymentId = $request->getHeaderLine('x-appwrite-id', $deploymentId);
            // TODO make `end >= $fileSize` in next breaking version
            if (is_null($start) || is_null($end) || is_null($fileSize) || $end > $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            $chunks = (int) ceil($fileSize / APP_LIMIT_UPLOAD_CHUNK_SIZE);
            $chunk = (int) ($start / APP_LIMIT_UPLOAD_CHUNK_SIZE) + 1;
        }

        if (!$fileSizeValidator->isValid($fileSize) && $functionSizeLimit !== 0) { // Check if file size is exceeding allowed limit
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE);
        }

        if (!$upload->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        // Save to storage
        $fileSize ??= $deviceForLocal->getFileSize($fileTmpName);
        $path = $deviceForFunctions->getPath($deploymentId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));

        $lockKey = 'functions:deployment:' . $project->getId() . ':' . $functionId . ':' . $deploymentId;

        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];
        $completed = false;

        $mergeUploadMetadata = function (array $stored, array $current): array {
            $merged = \array_merge($stored, $current);

            if (isset($stored['parts']) || isset($current['parts'])) {
                $parts = $stored['parts'] ?? [];
                foreach (($current['parts'] ?? []) as $part => $value) {
                    $parts[(int) $part] = $value;
                }
                \ksort($parts);

                $merged['parts'] = $parts;
                $merged['chunks'] = \count($parts);
            }

            return $merged;
        };

        $type = $request->getHeaderLine('x-sdk-language') === 'cli' ? 'cli' : 'manual';

        try {
            $locks($lockKey, 600, function () use ($activate, &$chunks, $commands, $contentRange, $dbForProject, $deploymentId, $deviceForFunctions, $entrypoint, $fileSize, &$function, &$metadata, $path, $type, &$completed, $response): void {
                $deployment = $dbForProject->getDocument('deployments', $deploymentId);

                if (!$deployment->isEmpty()) {
                    $chunks = $deployment->getAttribute('sourceChunksTotal', 1);
                    $uploaded = $deployment->getAttribute('sourceChunksUploaded', 0);
                    $metadata = $deployment->getAttribute('sourceMetadata', []);

                    if ($uploaded === $chunks) {
                        $response
                            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);

                        $completed = true;
                        return;
                    }
                }

                if ($deployment->isEmpty()) {
                    $deviceForFunctions->prepareUpload($path, $metadata['content_type'] ?? '', $chunks, $metadata);

                    if (!empty($contentRange)) {
                        $deployment = $dbForProject->createDocument('deployments', new Document([
                            '$id' => $deploymentId,
                            '$permissions' => [
                                Permission::read(Role::any()),
                                Permission::update(Role::any()),
                                Permission::delete(Role::any()),
                            ],
                            'resourceInternalId' => $function->getSequence(),
                            'resourceId' => $function->getId(),
                            'resourceType' => 'functions',
                            'entrypoint' => $entrypoint,
                            'buildCommands' => $commands,
                            'startCommand' => $function->getAttribute('startCommand', ''),
                            'sourcePath' => $path,
                            'sourceSize' => $fileSize,
                            'totalSize' => $fileSize,
                            'sourceChunksTotal' => $chunks,
                            'sourceChunksUploaded' => 0,
                            'activate' => $activate,
                            'sourceMetadata' => $metadata,
                            'type' => $type
                        ]));
                    }
                }
            }, timeout: 120.0);
        } catch (LockContention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment upload is busy. Try again.');
        }

        if ($completed) {
            $queueForEvents->reset();
            return;
        }

        $chunksUploaded = $deviceForFunctions->uploadChunk($fileTmpName, $path, $chunk, $chunks, $metadata);

        if (empty($chunksUploaded)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed moving file');
        }

        try {
            $locks($lockKey, 600, function () use ($activate, &$chunks, $chunksUploaded, $commands, $dbForProject, $deploymentId, $deviceForFunctions, $entrypoint, $fileSize, &$function, $functionId, $path, &$metadata, $mergeUploadMetadata, $platform, $project, $publisherForBuilds, $jobs, $queueForEvents, $response, $type): void {
                $deployment = $dbForProject->getDocument('deployments', $deploymentId);
                $uploaded = 0;

                if (!$deployment->isEmpty()) {
                    $chunks = $deployment->getAttribute('sourceChunksTotal', 1);
                    $uploaded = $deployment->getAttribute('sourceChunksUploaded', 0);
                    $metadata = $mergeUploadMetadata($deployment->getAttribute('sourceMetadata', []), $metadata);

                    if ($uploaded === $chunks) {
                        $queueForEvents->reset();

                        $response
                            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
                        return;
                    }
                }

                $chunksUploaded = max($uploaded, $chunksUploaded, (int) ($metadata['chunks'] ?? 0));

                if ($chunksUploaded === $chunks && $uploaded < $chunks) {
                    $deviceForFunctions->finalizeUpload($path, $chunks, $metadata);

                    if ($activate) {
                        // Remove deploy for all other deployments.
                        $activeDeployments = $dbForProject->find('deployments', [
                            Query::equal('activate', [true]),
                            Query::equal('resourceId', [$functionId]),
                            Query::equal('resourceType', ['functions'])
                        ]);

                        foreach ($activeDeployments as $activeDeployment) {
                            $dbForProject->updateDocument('deployments', $activeDeployment->getId(), new Document([
                                'activate' => false,
                            ]));
                        }
                    }

                    $fileSize = $deviceForFunctions->getFileSize($path);

                    // Build backend for manual-upload function deployments:
                    // 'orchestrator' (open-runtimes jobs-service, submitted below
                    // in the request flow) or 'executor' (default; enqueued to the
                    // Builds worker). Selected by _APP_BUILDS_BACKEND.
                    $useJobs = System::getEnv('_APP_BUILDS_BACKEND', 'executor') === 'orchestrator';

                    // Fields marking the deployment as queued. The build worker
                    // promotes 'waiting' → 'building' on the first callback. The
                    // jobs path also pre-declares buildPath so build.sh writes the
                    // output straight onto the mounted builds volume.
                    $buildFields = ['status' => 'waiting'];
                    if ($useJobs) {
                        $buildFields['buildPath'] = Job::buildPath($project->getId(), $deploymentId);
                    }

                    if ($deployment->isEmpty()) {
                        $deployment = $dbForProject->createDocument('deployments', new Document([
                            '$id' => $deploymentId,
                            '$permissions' => [
                                Permission::read(Role::any()),
                                Permission::update(Role::any()),
                                Permission::delete(Role::any()),
                            ],
                            'resourceInternalId' => $function->getSequence(),
                            'resourceId' => $function->getId(),
                            'resourceType' => 'functions',
                            'entrypoint' => $entrypoint,
                            'buildCommands' => $commands,
                            'startCommand' => $function->getAttribute('startCommand', ''),
                            'sourcePath' => $path,
                            'sourceSize' => $fileSize,
                            'totalSize' => $fileSize,
                            'sourceChunksTotal' => $chunks,
                            'sourceChunksUploaded' => $chunksUploaded,
                            'activate' => $activate,
                            'sourceMetadata' => $metadata,
                            'type' => $type,
                            ...$buildFields,
                        ]));

                    } else {
                        $deployment = $dbForProject->updateDocument('deployments', $deploymentId, new Document([
                            'sourceSize' => $fileSize,
                            'sourceChunksUploaded' => $chunksUploaded,
                            'sourceMetadata' => $metadata,
                            ...$buildFields,
                        ]));
                    }

                    if ($useJobs) {
                        $jobs->create(...Job::build($project, $function, $deployment, $platform));
                    } else {
                        // Default: build on the executor via the Builds worker.
                        $publisherForBuilds->enqueue(new BuildMessage(
                            project: $project,
                            resource: $function,
                            deployment: $deployment,
                            type: BUILD_TYPE_DEPLOYMENT,
                            platform: $platform,
                        ));
                    }
                } else {
                    $deployment = $dbForProject->updateDocument('deployments', $deploymentId, new Document([
                        'sourceChunksUploaded' => $chunksUploaded,
                        'sourceMetadata' => $metadata,
                    ]));
                }

                $metadata = null;

                if ($chunksUploaded === $chunks) {
                    $queueForEvents
                        ->setParam('functionId', $function->getId())
                        ->setParam('deploymentId', $deployment->getId());
                } else {
                    $queueForEvents->setEvent('');
                }

                $response
                    ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                    ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
            }, timeout: 120.0);
        } catch (LockContention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment upload is busy. Try again.');
        }
    }

}
