<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Runners\Leases\Artifacts;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Artifact\Source;
use Appwrite\Distribution\Artifact\Status;
use Appwrite\Distribution\Artifact\Type;
use Appwrite\Distribution\Builds;
use Appwrite\Distribution\Lease\Status as LeaseStatus;
use Appwrite\Distribution\Lease\Token;
use Appwrite\Distribution\Leases;
use Appwrite\Distribution\Platform;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Usage\Distribution as DistributionUsage;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Validator\File;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;

/**
 * The IPA a runner built for its lease, uploaded in chunks. The artifact id is derived
 * from the lease, so a first chunk sent again lands on the same artifact and no lease can
 * write to another's. The last chunk leaves the artifact processing: the worker readies
 * it once the runner's result event has recorded what the IPA is.
 */
class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRunnerLeaseArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/runners/:runnerId/leases/:leaseId/artifacts')
            ->desc('Create runner lease artifact')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['url', 'ip'], 100)])
            ->param('runnerId', '', new UID(), 'Runner ID.')
            ->param('leaseId', '', new UID(), 'Lease ID.')
            ->param('file', [], new File(), 'The built IPA.', skipValidation: true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('deviceForDistribution')
            ->inject('deviceForLocal')
            ->inject('plan')
            ->inject('locks')
            ->inject('usage')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function action(
        string $runnerId,
        string $leaseId,
        mixed $file,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Authorization $authorization,
        Device $deviceForDistribution,
        Device $deviceForLocal,
        array $plan,
        callable $locks,
        UsageContext $usage,
    ): void {
        // Operators switch Distribution on with _APP_DISTRIBUTION. The api hook enforces it by SDK namespace, which internal routes do not carry.
        if (System::getEnv('_APP_DISTRIBUTION', 'disabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
        }

        $header = $request->getHeaderLine('authorization');
        $token = \str_starts_with($header, 'Bearer ') ? \substr($header, \strlen('Bearer ')) : '';
        $verified = Token::verify($token, Leases::secret());
        if ($verified === null || $verified->runnerId !== $runnerId || $verified->leaseId !== $leaseId) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid lease token.');
        }

        if ($verified->projectInternalId !== (string) $project->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_LEASE_NOT_FOUND);
        }

        $lease = $authorization->skip(fn () => (new Leases($dbForPlatform))->held($verified, $token));
        $authorization->skip(fn () => $this->upload($lease, $request, $response, $project, $dbForProject, $deviceForDistribution, $deviceForLocal, $plan, $locks, $usage));
    }

    /**
     * Reads and writes past document permissions only the build the held lease names, its
     * app and the artifact derived from the lease.
     *
     * @param array<string, mixed> $plan
     */
    private function upload(
        Document $lease,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        Device $deviceForDistribution,
        Device $deviceForLocal,
        array $plan,
        callable $locks,
        UsageContext $usage,
    ): void {
        $build = $dbForProject->getDocument('distributionBuilds', (string) $lease->getAttribute('buildId', ''));
        if (
            $lease->getAttribute('status') !== LeaseStatus::Leased->value
            || Leases::isExpired($lease, \time())
            || $build->isEmpty()
            || $build->getAttribute('jobId') !== $lease->getId()
            || !\in_array($build->getAttribute('status'), Builds::ACTIVE, true)
        ) {
            throw new Exception(Exception::DISTRIBUTION_LEASE_NOT_FOUND);
        }

        $app = $dbForProject->getDocument('distributionApps', (string) $build->getAttribute('appId', ''));
        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_LEASE_NOT_FOUND);
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

        if (!(new FileExt([Type::Ipa->value]))->isValid($fileName)) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'Only ' . Type::Ipa->value . ' files can be uploaded for an iOS build.');
        }

        $artifactId = Leases::artifact($lease->getId());
        $requested = $request->getHeaderLine('x-appwrite-id');
        if ($requested !== '' && $requested !== $artifactId) {
            throw new Exception(Exception::STORAGE_INVALID_APPWRITE_ID, 'The artifact ID must be the one the first chunk of this lease returned.');
        }

        $contentRange = $request->getHeaderLine('content-range');
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();

            if (\is_null($start) || \is_null($end) || \is_null($fileSize) || $end >= $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
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
        $path = $deviceForDistribution->getPath(Builds::path($artifactId, Type::Ipa->value));
        $lockKey = 'distribution:artifact:' . $project->getId() . ':' . $app->getId() . ':' . $artifactId;
        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];
        $completed = false;

        try {
            $locks($lockKey, 600, function () use ($app, $artifactId, $build, &$chunks, &$completed, $dbForProject, $deviceForDistribution, $fileName, $fileSize, &$metadata, $path, $response): void {
                $artifact = $this->getArtifact($dbForProject, $build, $artifactId);

                if (!$artifact->isEmpty()) {
                    $this->assertUpload($artifact, $fileSize);

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

                $retention = (int) System::getEnv('_APP_DISTRIBUTION_ARTIFACT_RETENTION', Builds::ARTIFACT_RETENTION);

                $dbForProject->createDocument('distributionArtifacts', new Document([
                    '$id' => $artifactId,
                    'appId' => $app->getId(),
                    'appInternalId' => $app->getSequence(),
                    'targetId' => (string) $build->getAttribute('targetId', ''),
                    'targetInternalId' => (string) $build->getAttribute('targetInternalId', ''),
                    'platform' => Platform::Ios->value,
                    'type' => Type::Ipa->value,
                    'source' => Source::Build->value,
                    'buildId' => $build->getId(),
                    'buildInternalId' => $build->getSequence(),
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
            return;
        }

        try {
            $locks($lockKey, 600, function () use ($app, $artifactId, $build, $chunk, &$chunks, $dbForProject, $deviceForDistribution, $deviceForLocal, $fileSize, $fileTmpName, &$metadata, $path, $response, $usage): void {
                $artifact = $this->getArtifact($dbForProject, $build, $artifactId);

                if ($artifact->isEmpty()) {
                    throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);
                }

                $this->assertUpload($artifact, $fileSize);

                $chunks = $artifact->getAttribute('chunksTotal', 1);
                $uploaded = $artifact->getAttribute('chunksUploaded', 0);
                $metadata = $this->mergeMetadata($artifact->getAttribute('metadata', []), $metadata);

                if ($uploaded === $chunks) {
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

                DistributionUsage::addTransfer($usage, $app, METRIC_DISTRIBUTION_ARTIFACTS_INBOUND, $deviceForLocal->getFileSize($fileTmpName));

                $chunksUploaded = \max($uploaded, $chunksUploaded, (int) ($metadata['chunks'] ?? 0));

                if ($chunksUploaded < $chunks) {
                    $artifact = $dbForProject->updateDocument('distributionArtifacts', $artifact->getId(), new Document([
                        'chunksUploaded' => $chunksUploaded,
                        'metadata' => $metadata,
                    ]));

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

                $response
                    ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                    ->dynamic($artifact, Response::MODEL_DISTRIBUTION_ARTIFACT);
            }, timeout: 120.0);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Artifact upload is busy. Try again.');
        }
    }

    private function getArtifact(Database $dbForProject, Document $build, string $artifactId): Document
    {
        $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);

        if ($artifact->isEmpty()) {
            return $artifact;
        }

        // The id is derived from the lease, so only the lease's own build artifact can be resumed.
        if (
            $artifact->getAttribute('source') !== Source::Build->value
            || $artifact->getAttribute('buildId') !== $build->getId()
        ) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);
        }

        return $artifact;
    }

    private function assertUpload(Document $artifact, int $fileSize): void
    {
        // The first chunk fixed the size and chunk count.
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
