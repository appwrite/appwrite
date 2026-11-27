<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Artifacts;

use Appwrite\Distribution\Artifact\Source;
use Appwrite\Distribution\Artifact\Status;
use Appwrite\Distribution\Artifact\Type;
use Appwrite\Event\Event;
use Appwrite\Event\Message\DistributionBuild as DistributionBuildMessage;
use Appwrite\Event\Message\DistributionBuildAction;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request\Validator\File;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/artifacts')
            ->desc('Create artifact')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].artifacts.[artifactId].create')
            ->label('audits.event', 'distribution.artifact.create')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'artifacts',
                name: 'createArtifact',
                description: <<<EOT
                Upload a signed or unsigned app binary to an app. Accepts an IPA for iOS or an AAB for Android, sent in chunks. Once the last chunk arrives the binary is inspected: its bundle ID or package name selects the target, and an unsigned AAB is signed with the target's signing identity. Use the get artifact endpoint to follow its status.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DISTRIBUTION_ARTIFACT,
                    )
                ],
                requestType: ContentType::MULTIPART,
                type: MethodType::UPLOAD,
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('file', [], new File(), 'App binary to upload: an IPA for iOS or an AAB for Android.', skipValidation: true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('publisherForDistributionBuilds')
            ->inject('deviceForDistribution')
            ->inject('deviceForLocal')
            ->inject('plan')
            ->inject('locks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function action(
        string $appId,
        mixed $file,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        DistributionBuildPublisher $publisherForDistributionBuilds,
        Device $deviceForDistribution,
        Device $deviceForLocal,
        array $plan,
        callable $locks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $file = $request->getFiles('file');

        // GraphQL multipart spec adds files with index keys
        if (empty($file)) {
            $file = $request->getFiles(0);
        }

        if (empty($file)) {
            throw new Exception(Exception::STORAGE_FILE_EMPTY, 'No file sent');
        }

        $sizeLimit = (int) System::getEnv('_APP_DISTRIBUTION_SIZE_LIMIT', '4000000000');

        if (isset($plan['distributionArtifactSize'])) {
            $sizeLimit = $plan['distributionArtifactSize'] * 1000 * 1000;
        }

        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        if (!(new FileExt(Type::values()))->isValid($fileName)) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'Only ' . \implode(' and ', Type::values()) . ' files can be uploaded.');
        }

        $type = Type::from(\strtolower(\pathinfo($fileName, PATHINFO_EXTENSION)));

        $contentRange = $request->getHeaderLine('content-range');
        $artifactId = Id::unique();
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $artifactId = $request->getHeaderLine('x-appwrite-id', $artifactId);

            if (\is_null($start) || \is_null($end) || \is_null($fileSize) || $end >= $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            if (!(new UID())->isValid($artifactId)) {
                throw new Exception(Exception::STORAGE_INVALID_APPWRITE_ID);
            }

            $chunks = (int) \ceil($fileSize / APP_LIMIT_UPLOAD_CHUNK_SIZE);
            $chunk = (int) ($start / APP_LIMIT_UPLOAD_CHUNK_SIZE) + 1;
        }

        if ($sizeLimit !== 0 && !(new FileSize($sizeLimit))->isValid($fileSize)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE, 'The binary exceeds the maximum size of ' . $sizeLimit . ' bytes.');
        }

        if (!(new Upload())->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        $fileSize ??= $deviceForLocal->getFileSize($fileTmpName);
        $path = $deviceForDistribution->getPath($artifactId . '.' . $type->value);
        $lockKey = 'distribution:artifact:' . $project->getId() . ':' . $app->getId() . ':' . $artifactId;
        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];
        $completed = false;

        try {
            $locks($lockKey, 600, function () use ($app, $artifactId, &$chunks, &$completed, $dbForProject, $deviceForDistribution, $fileName, $fileSize, &$metadata, $path, $response, $type): void {
                $artifact = $this->getArtifact($dbForProject, $app, $artifactId);

                if (!$artifact->isEmpty()) {
                    $this->assertUpload($artifact, $type, $fileSize);

                    $chunks = $artifact->getAttribute('chunksTotal', 1);
                    $metadata = $artifact->getAttribute('metadata', []);

                    if ($artifact->getAttribute('chunksUploaded', 0) === $chunks) {
                        $response
                            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                            ->dynamic($artifact, Response::MODEL_DISTRIBUTION_ARTIFACT);

                        $completed = true;
                    }

                    return;
                }

                $deviceForDistribution->prepare($path, $metadata['content_type'] ?? '', $chunks, $metadata);

                $retention = (int) System::getEnv('_APP_DISTRIBUTION_ARTIFACT_RETENTION', '7776000');

                $dbForProject->createDocument('distributionArtifacts', new Document([
                    '$id' => $artifactId,
                    'appId' => $app->getId(),
                    'appInternalId' => $app->getSequence(),
                    'type' => $type->value,
                    'source' => Source::Upload->value,
                    'status' => Status::Uploading->value,
                    'path' => $path,
                    'metadata' => $metadata,
                    'name' => $fileName,
                    'size' => $fileSize,
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => 0,
                    'expiresAt' => DateTime::addSeconds(new \DateTime(), $retention),
                ]));
            }, timeout: 120.0);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Artifact upload is busy. Try again.');
        }

        if ($completed) {
            $queueForEvents->reset();
            return;
        }

        try {
            $locks($lockKey, 600, function () use ($app, $artifactId, $chunk, &$chunks, $dbForProject, $deviceForDistribution, $deviceForLocal, $fileSize, $fileTmpName, &$metadata, $path, $project, $publisherForDistributionBuilds, $queueForEvents, $response, $type): void {
                $artifact = $this->getArtifact($dbForProject, $app, $artifactId);

                if ($artifact->isEmpty()) {
                    throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);
                }

                $this->assertUpload($artifact, $type, $fileSize);

                $chunks = $artifact->getAttribute('chunksTotal', 1);
                $uploaded = $artifact->getAttribute('chunksUploaded', 0);
                $metadata = $this->mergeMetadata($artifact->getAttribute('metadata', []), $metadata);

                if ($uploaded === $chunks) {
                    $queueForEvents->reset();

                    $response
                        ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                        ->dynamic($artifact, Response::MODEL_DISTRIBUTION_ARTIFACT);
                    return;
                }

                // Chunk writes and assembly share the lock so no request counts or assembles a partially written chunk.
                $chunksUploaded = $deviceForDistribution->upload(
                    $deviceForLocal->read($fileTmpName),
                    $path,
                    $metadata['content_type'] ?? '',
                    $chunk,
                    $chunks,
                    $metadata
                );

                if (empty($chunksUploaded)) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed moving file');
                }

                $chunksUploaded = \max($uploaded, $chunksUploaded, (int) ($metadata['chunks'] ?? 0));

                if ($chunksUploaded < $chunks) {
                    $artifact = $dbForProject->updateDocument('distributionArtifacts', $artifact->getId(), new Document([
                        'chunksUploaded' => $chunksUploaded,
                        'metadata' => $metadata,
                    ]));

                    $queueForEvents->reset();

                    $response
                        ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                        ->dynamic($artifact, Response::MODEL_DISTRIBUTION_ARTIFACT);
                    return;
                }

                $deviceForDistribution->finalize($path, $chunks, $metadata);

                // A chunk can carry more or fewer bytes than its range announces.
                if ($deviceForDistribution->getFileSize($path) !== $fileSize) {
                    $deviceForDistribution->delete($path);

                    // Marked fully uploaded so a retry returns the invalid artifact instead of writing to the removed file.
                    $dbForProject->updateDocument('distributionArtifacts', $artifact->getId(), new Document([
                        'status' => Status::Invalid->value,
                        'chunksUploaded' => $chunks,
                        'metadata' => $metadata,
                        'errorCode' => Exception::STORAGE_INVALID_CONTENT_RANGE,
                        'errorMessage' => 'Uploaded size does not match the size announced by Content-Range.',
                    ]));

                    throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
                }

                $artifact = $dbForProject->updateDocument('distributionArtifacts', $artifact->getId(), new Document([
                    'status' => Status::Processing->value,
                    'chunksUploaded' => $chunksUploaded,
                    'metadata' => $metadata,
                ]));

                $publisherForDistributionBuilds->enqueue(new DistributionBuildMessage(
                    project: $project,
                    action: DistributionBuildAction::Inspect,
                    id: $artifact->getId(),
                ));

                $queueForEvents
                    ->setParam('appId', $app->getId())
                    ->setParam('artifactId', $artifact->getId());

                $response
                    ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                    ->dynamic($artifact, Response::MODEL_DISTRIBUTION_ARTIFACT);
            }, timeout: 120.0);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Artifact upload is busy. Try again.');
        }
    }

    private function getArtifact(Database $dbForProject, Document $app, string $artifactId): Document
    {
        $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);

        if ($artifact->isEmpty()) {
            return $artifact;
        }

        // Resuming an upload must not cross apps or reach a built artifact.
        if (
            $artifact->getAttribute('appInternalId') !== $app->getSequence()
            || $artifact->getAttribute('source') !== Source::Upload->value
        ) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);
        }

        return $artifact;
    }

    private function assertUpload(Document $artifact, Type $type, int $fileSize): void
    {
        // The first chunk fixed the binary format, size and chunk count.
        if ($artifact->getAttribute('type') !== $type->value) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'Every chunk must carry the file extension of the first chunk.');
        }

        if ((int) $artifact->getAttribute('size', 0) !== $fileSize) {
            throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
        }
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $stored, array $current): array
    {
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
    }
}
