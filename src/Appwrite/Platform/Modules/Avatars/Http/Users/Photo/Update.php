<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Users\Photo;

use Appwrite\Abuse\Limit;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Photo\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request\Validator\File;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;

class Update extends Base
{
    private const ALLOWED_EXTENSIONS = [
        FileExt::TYPE_PNG,
        FileExt::TYPE_JPG,
        FileExt::TYPE_JPEG,
        'webp',
    ];

    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    public static function getName(): string
    {
        return 'updateUserPhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/avatars/users/:userId/photo')
            ->desc('Update photo')
            ->groups(['api', 'avatars', 'users'])
            // User photos belong to Users too, so the project's Users switch still covers them
            ->label('services', ['users'])
            ->label('event', 'users.[userId].update.avatar')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'updateUserPhoto',
                description: <<<'EOT'
                Update the profile photo of a user. The uploaded image takes priority over every other photo source, including OAuth2 identity photos, Gravatar, and Libravatar. Updating an already customized photo replaces it. The image must be at most 5MB and is sent in a single request.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    ),
                ],
                requestType: ContentType::MULTIPART,
                type: MethodType::UPLOAD,
            ))
            ->param('userId', '', new UID(), 'User ID.')
            ->param('file', [], new File(), 'Binary image file of at most 5MB. Allowed file types are png, jpg, jpeg, and webp.', skipValidation: true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('deviceForLocal')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        mixed $file,
        Request $request,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Device $deviceForFiles,
        Device $deviceForLocal,
    ): void {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        // Photos fit in one chunk, so a chunked upload is refused rather than stored as a partial image
        if (!empty($request->getHeaderLine('content-range'))) {
            throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE, 'Photo must be sent in a single request');
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

        if (!(new Upload())->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        if (!(new FileExt(self::ALLOWED_EXTENSIONS))->isValid($fileName)) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'File extension not allowed');
        }

        $size = $deviceForLocal->getFileSize($fileTmpName);

        if (!(new FileSize(APP_LIMIT_UPLOAD_CHUNK_SIZE))->isValid($size)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE, 'Photo must be at most 5MB');
        }

        $mimeType = $deviceForLocal->getFileMimeType($fileTmpName);

        if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'Photo must be a PNG, JPEG, or WebP image');
        }

        $updated = $this->replacePhoto($user, $deviceForLocal->read($fileTmpName), $size, $mimeType, $dbForProject, $deviceForFiles);

        $queueForEvents->setParam('userId', $userId);

        $response->dynamic($updated, Response::MODEL_USER);
    }
}
