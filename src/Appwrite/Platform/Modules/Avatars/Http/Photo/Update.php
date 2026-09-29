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
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Pools\Group;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;

class Update extends Action
{
    use HTTP;

    private const LOCK_TTL = 120;

    private const ALLOWED_EXTENSIONS = [
        FileExt::TYPE_PNG,
        FileExt::TYPE_JPG,
        FileExt::TYPE_JPEG,
        FileExt::TYPE_GIF,
        'webp',
    ];

    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
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
            ->label('audits.resource', 'user/{user.$id}')
            ->label('audits.userId', '{user.$id}')
            ->label('usage.resource', 'user/{user.$id}')
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
            ->param('file', [], new File(), 'Binary image file. Allowed file types are png, jpg, jpeg, gif, and webp.', skipValidation: true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('deviceForLocal')
            ->inject('plan')
            ->inject('pools')
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
        Group $pools,
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

        $upload = new Upload();
        if (!$upload->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        $fileExt = new FileExt(self::ALLOWED_EXTENSIONS);
        if (!$fileExt->isValid($fileName)) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'File extension not allowed');
        }

        $photoId = ID::unique();
        $chunk = 1;
        $chunks = 1;

        if (!empty($request->getHeaderLine('content-range'))) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();

            // Every chunk but the last is exactly one chunk long, and the body is exactly the declared range
            if (
                \is_null($start) || \is_null($end) || \is_null($fileSize)
                || $start > $end || $end >= $fileSize
                || $start % APP_LIMIT_UPLOAD_CHUNK_SIZE !== 0
                || ($end !== $fileSize - 1 && $end - $start + 1 !== APP_LIMIT_UPLOAD_CHUNK_SIZE)
                || $deviceForLocal->getFileSize($fileTmpName) !== $end - $start + 1
            ) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            $photoId = $request->getHeaderLine('x-appwrite-id', $photoId);
            if (!(new UID())->isValid($photoId)) {
                throw new Exception(Exception::STORAGE_INVALID_APPWRITE_ID);
            }

            $chunks = (int) \ceil($fileSize / APP_LIMIT_UPLOAD_CHUNK_SIZE);
            $chunk = (int) ($start / APP_LIMIT_UPLOAD_CHUNK_SIZE) + 1;
        }

        $sizeLimit = (int) System::getEnv('_APP_AVATAR_SIZE_LIMIT', '20000000');
        if (isset($plan['avatarSize'])) {
            $sizeLimit = $plan['avatarSize'] * 1000 * 1000;
        }

        if (!(new FileSize($sizeLimit))->isValid($fileSize)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE, 'File size not allowed');
        }

        $custom = new Custom($deviceForFiles);
        $userId = $user->getId();
        $path = $custom->getPath($userId, $photoId);
        $lockKey = 'photos:' . $project->getId() . ':' . $userId;

        // One lock per user serialises chunks, activation and deletion of their photos
        $update = function (Distributed $lock) use ($chunk, $chunks, $custom, $dbForProject, $deviceForFiles, $deviceForLocal, $fileSize, $fileTmpName, $path, $photoId, $queueForEvents, $response, $userId, $user): void {
            $photo = $dbForProject->getDocument('photos', $photoId);

            if ($photo->isEmpty()) {
                // A missing upload past its first chunk was replaced or deleted meanwhile
                if ($chunk !== 1) {
                    throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                }

                $photo = $dbForProject->createDocument('photos', new Document([
                    '$id' => $photoId,
                    '$permissions' => [
                        Permission::read(Role::user($userId)),
                        Permission::update(Role::user($userId)),
                        Permission::delete(Role::user($userId)),
                    ],
                    'userId' => $userId,
                    'userInternalId' => (string) $user->getSequence(),
                    'sizeOriginal' => $fileSize,
                    'sizeActual' => 0,
                    'mimeType' => '',
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => 0,
                    'metadata' => [],
                ]));
            } elseif ($photo->getAttribute('userId') !== $userId) {
                throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
            } elseif ($photo->getAttribute('sizeOriginal') !== $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            if ($photo->getAttribute('chunksUploaded') < $chunks) {
                $metadata = $photo->getAttribute('metadata', []);
                $metadata['content_type'] = $deviceForLocal->getFileMimeType($fileTmpName);

                $chunksUploaded = $deviceForFiles->upload($deviceForLocal->read($fileTmpName), $path, $metadata['content_type'], $chunk, $chunks, $metadata);

                if (!$lock->isHeld()) {
                    throw new Contention('Photo lock expired during upload');
                }

                if ($chunksUploaded < $chunks) {
                    $photo = $dbForProject->updateDocument('photos', $photoId, new Document([
                        'chunksUploaded' => $chunksUploaded,
                        'metadata' => $metadata,
                    ]));

                    $queueForEvents->reset();
                    $response->dynamic($photo, Response::MODEL_PHOTO);

                    return;
                }

                $mimeType = $deviceForFiles->getFileMimeType($path);

                if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                    $deviceForFiles->delete($path);
                    $dbForProject->deleteDocument('photos', $photoId);

                    throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'Photo must be a PNG, JPEG, GIF, or WebP image');
                }

                $photo = $dbForProject->updateDocument('photos', $photoId, new Document([
                    'sizeActual' => $deviceForFiles->getFileSize($path),
                    'mimeType' => $mimeType,
                    'chunksUploaded' => $chunksUploaded,
                    'metadata' => $metadata,
                ]));
            }

            // A resent final chunk finds its photo active already
            if ($dbForProject->getDocument('users', $userId)->getAttribute('avatar', '') === $photoId) {
                $queueForEvents->reset();
                $response->dynamic($photo, Response::MODEL_PHOTO);

                return;
            }

            $dbForProject->updateDocument('users', $userId, new Document([
                'avatar' => $photoId,
            ]));

            // Drops the replaced photo and any abandoned uploads; a file that fails to delete keeps its document for the next attempt
            $others = $dbForProject->find('photos', [
                Query::equal('userInternalId', [(string) $user->getSequence()]),
                Query::notEqual('$id', $photoId),
                Query::limit(APP_LIMIT_COUNT),
            ]);

            foreach ($others as $other) {
                if ($custom->delete($other)) {
                    $dbForProject->deleteDocument('photos', $other->getId());
                } else {
                    Console::warning('Failed to remove previous photo ' . $other->getId());
                }
            }

            $queueForEvents->setParam('userId', $userId);
            $response->dynamic($photo, Response::MODEL_PHOTO);
        };

        try {
            $pools->get('lock')->use(function (\Redis $redis) use ($lockKey, $update): void {
                $lock = new Distributed($redis, $lockKey, self::LOCK_TTL);
                $lock->withLock(fn () => $update($lock), timeout: 30.0);
            });
        } catch (Contention) {
            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
        }
    }
}
