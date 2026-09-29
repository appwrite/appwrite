<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

use Appwrite\AvatarPhotos\Providers\Custom;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request\Validator\File;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;

class Update extends Action
{
    use HTTP;

    private const LOCK_TTL = 600;

    private const ALLOWED_EXTENSIONS = [
        FileExt::TYPE_PNG,
        FileExt::TYPE_JPG,
        FileExt::TYPE_JPEG,
        FileExt::TYPE_GIF,
        'webp',
    ];

    public static function getName(): string
    {
        return 'updatePhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/avatars/photo')
            ->desc('Update photo')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.write')
            ->label('event', 'users.[userId].update.avatar')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('usage.resource', 'user/{response.userId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'updatePhoto',
                description: <<<'EOT'
                Update the profile photo of the currently authenticated user. The uploaded image takes priority over every other photo source, including OAuth2 identity photos, Gravatar, and Libravatar. Updating an already customized photo replaces it. Sending the file in chunks is supported: pass a Content-Range header on each request and reuse the upload ID returned by the first response in the x-appwrite-id header.
                EOT,
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PHOTO,
                    ),
                ],
                requestType: ContentType::MULTIPART,
                type: MethodType::UPLOAD,
            ))
            ->param('file', [], new File, 'Binary image file. Allowed file types are png, jpg, jpeg, gif, and webp.', skipValidation: true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('deviceForLocal')
            ->inject('plan')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        mixed $file,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
        Device $deviceForLocal,
        array $plan,
        callable $locks,
    ): void {
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $file = $request->getFiles('file');

        // GraphQL multipart spec adds files with index keys
        if (empty($file)) {
            $file = $request->getFiles(0);
        }

        if (empty($file)) {
            throw new Exception(Exception::STORAGE_FILE_EMPTY);
        }

        // Make sure we handle a single file and multiple files the same way
        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        $contentRange = $request->getHeaderLine('content-range');
        $uploadId = ID::unique();
        $chunk = 1;
        $chunks = 1;

        if (! empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $uploadId = $request->getHeaderLine('x-appwrite-id', $uploadId);
            if (is_null($start) || is_null($end) || is_null($fileSize) || $end > $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            $idValidator = new UID;
            if (! $idValidator->isValid($uploadId)) {
                throw new Exception(Exception::STORAGE_INVALID_APPWRITE_ID);
            }

            $chunks = (int) ceil($fileSize / APP_LIMIT_UPLOAD_CHUNK_SIZE);
            $chunk = (int) ($start / APP_LIMIT_UPLOAD_CHUNK_SIZE) + 1;
        }

        $fileExt = new FileExt(self::ALLOWED_EXTENSIONS);
        if (! $fileExt->isValid($fileName)) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'File extension not allowed');
        }

        $avatarSizeLimit = (int) System::getEnv('_APP_AVATAR_SIZE_LIMIT', '20000000');
        if (isset($plan['avatarSize'])) {
            $avatarSizeLimit = $plan['avatarSize'] * 1000 * 1000;
        }

        $fileSizeValidator = new FileSize($avatarSizeLimit);
        if (! $fileSizeValidator->isValid($fileSize)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE, 'File size not allowed');
        }

        $upload = new Upload;
        if (! $upload->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        $fileSize ??= $deviceForLocal->getFileSize($fileTmpName);
        $userId = $user->getId();
        $userInternalId = (string) $user->getSequence();
        $path = Custom::getPath($deviceForFiles, $userId, $uploadId);
        $lockKey = 'photos:'.$project->getId().':'.$uploadId;

        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];

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

        try {
            $locks($lockKey, self::LOCK_TTL, function (Distributed $lock) use ($chunk, $chunks, $dbForProject, $deviceForFiles, $deviceForLocal, $fileSize, $fileTmpName, $metadata, $mergeUploadMetadata, $path, $queueForEvents, $response, $uploadId, $userId, $userInternalId): void {
                $photo = $dbForProject->getDocument('photos', $uploadId);
                $uploaded = 0;

                if ($photo->isEmpty()) {
                    $deviceForFiles->prepare($path, $metadata['content_type'] ?? '', $chunks, $metadata);

                    try {
                        $photo = $dbForProject->createDocument('photos', new Document([
                            '$id' => $uploadId,
                            '$permissions' => [
                                Permission::read(Role::any()),
                                Permission::update(Role::any()),
                                Permission::delete(Role::any()),
                            ],
                            'userId' => $userId,
                            'userInternalId' => $userInternalId,
                            'sizeOriginal' => $fileSize,
                            'sizeActual' => 0,
                            'mimeType' => '',
                            'chunksTotal' => $chunks,
                            'chunksUploaded' => 0,
                            'metadata' => $metadata,
                        ]));
                    } catch (DuplicateException) {
                        throw new Exception(Exception::STORAGE_FILE_ALREADY_EXISTS);
                    }
                } else {
                    if ($photo->getAttribute('userId') !== $userId) {
                        throw new Exception(Exception::AVATAR_NOT_FOUND);
                    }

                    $chunks = (int) $photo->getAttribute('chunksTotal', 1);
                    $uploaded = (int) $photo->getAttribute('chunksUploaded', 0);
                    $metadata = $mergeUploadMetadata($photo->getAttribute('metadata', []), $metadata);

                    if ($uploaded === $chunks) {
                        $queueForEvents->reset();
                        $response->dynamic($photo, Response::MODEL_PHOTO);

                        return;
                    }
                }

                if (! $lock->refresh()) {
                    throw new LockContention('Upload lease lost before transfer: '.$lock->token());
                }

                $chunksUploaded = $deviceForFiles->upload(
                    $deviceForLocal->read($fileTmpName),
                    $path,
                    $metadata['content_type'] ?? '',
                    $chunk,
                    $chunks,
                    $metadata
                );

                if (! $lock->isHeld()) {
                    throw new LockContention('Upload lease lost after transfer: '.$lock->token());
                }

                if (empty($chunksUploaded)) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed uploading photo');
                }

                $chunksUploaded = max($uploaded, $chunksUploaded, (int) ($metadata['chunks'] ?? 0));

                if ($chunksUploaded < $chunks) {
                    $photo = $dbForProject->updateDocument('photos', $uploadId, new Document([
                        'chunksUploaded' => $chunksUploaded,
                        'metadata' => $metadata,
                    ]));

                    $queueForEvents->reset();
                    $response->dynamic($photo, Response::MODEL_PHOTO);

                    return;
                }

                $deviceForFiles->finalize($path, $chunks, $metadata);

                $mimeType = $deviceForFiles->getFileMimeType($path);

                if (! str_starts_with($mimeType, 'image/')) {
                    $deviceForFiles->delete($path);
                    $dbForProject->deleteDocument('photos', $uploadId);

                    throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'Uploaded file is not an image');
                }

                $photo = $dbForProject->updateDocument('photos', $uploadId, new Document([
                    'sizeActual' => $deviceForFiles->getFileSize($path),
                    'mimeType' => $mimeType,
                    'chunksUploaded' => $chunksUploaded,
                    'metadata' => $metadata,
                ]));

                $dbForProject->updateDocument('users', $userId, new Document([
                    'avatar' => $uploadId,
                ]));

                $previous = $dbForProject->find('photos', [
                    Query::equal('userInternalId', [$userInternalId]),
                    Query::notEqual('$id', $uploadId),
                    Query::limit(APP_LIMIT_COUNT),
                ]);

                foreach ($previous as $old) {
                    $dbForProject->deleteDocument('photos', $old->getId());

                    if (! $deviceForFiles->delete(Custom::getPath($deviceForFiles, $userId, $old->getId()))) {
                        Console::warning('Failed to remove previous photo file: '.$old->getId());
                    }
                }

                $queueForEvents->setParam('userId', $userId);
                $response->dynamic($photo, Response::MODEL_PHOTO);
            }, timeout: 120.0);
        } catch (LockContention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Photo upload is busy. Try again.');
        }
    }
}
