<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Artifacts\Download;

use Appwrite\Distribution\Artifact\Status;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public const string CONTENT_TYPE = 'application/octet-stream';

    public static function getName(): string
    {
        return 'getArtifactDownload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/apps/:appId/artifacts/:artifactId/download')
            ->desc('Get artifact download')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.read')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'artifacts',
                name: 'getArtifactDownload',
                description: <<<EOT
                Download the binary of an artifact by its unique ID. The response has a 'Content-Disposition: attachment' header with the original file name. Send a 'Range' header to download the binary in parts.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::ANY,
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'Environment', 'ImpersonateUserId'],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('artifactId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Artifact ID.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('deviceForDistribution')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $artifactId,
        Request $request,
        Response $response,
        Database $dbForProject,
        Device $deviceForDistribution,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);

        if ($artifact->isEmpty() || $artifact->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);
        }

        if ($artifact->getAttribute('status') === Status::Uploading->value) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_READY, 'The artifact is still uploading.');
        }

        $path = $artifact->getAttribute('path', '');

        if (empty($path) || !$deviceForDistribution->exists($path)) {
            throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND, 'The binary of the artifact is no longer stored.');
        }

        $size = (int) $artifact->getAttribute('size', 0);

        $response
            ->setContentType(self::CONTENT_TYPE)
            ->addHeader('Cache-Control', 'private, no-store')
            ->addHeader('Content-Security-Policy', "sandbox; script-src 'none';")
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContentDisposition(Response::DISPOSITION_ATTACHMENT, (string) $artifact->getAttribute('name', ''));

        if (!empty($request->getHeaderLine('range'))) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null || $end - $start > APP_STORAGE_READ_BUFFER) {
                $end = $start + MAX_OUTPUT_CHUNK_SIZE - 1;
            }

            // RFC 9110: a last-byte-pos past the end of the file is clamped, not rejected.
            $end = \min($end, $size - 1);

            if ($unit !== 'bytes' || $start > $end) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $length = $end - $start + 1;
            $data = (string) $deviceForDistribution->read($path, $start, $length);

            if (\strlen($data) !== $length) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Incomplete file read.');
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', (string) $length)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT)
                ->send($data);
            return;
        }

        // One backend read pins the representation; bytes are checked against the stored size as they stream.
        $remaining = $size;
        $started = false;

        try {
            $deviceForDistribution->stream($path, static function (string $chunk) use ($response, &$remaining, &$started): void {
                $remaining -= \strlen($chunk);

                if ($remaining < 0) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'File read exceeded its captured size.');
                }

                if ($chunk !== '') {
                    $started = true;
                    $response->chunk($chunk, false);
                }
            });

            if ($remaining !== 0) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Incomplete file read.');
            }

            $response->chunk('', true);
        } catch (\Throwable $error) {
            if ($started) {
                $response->abort();
            }

            throw $error;
        }
    }
}
