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
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;

class Update extends Action
{
    use HTTP;

    private const ALLOWED_EXTENSIONS = [
        FileExt::TYPE_PNG,
        FileExt::TYPE_JPG,
        FileExt::TYPE_JPEG,
    ];

    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
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
                Update the profile photo of the currently authenticated user. The uploaded image takes priority over every other photo source, including OAuth2 identity photos, Gravatar, and Libravatar. Updating an already customized photo replaces it. The image must be at most 5MB and is sent in a single request.
                EOT,
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    ),
                ],
                requestType: ContentType::MULTIPART,
                type: MethodType::UPLOAD,
            ))
            ->param('file', [], new File(), 'Binary image file of at most 5MB. Allowed file types are png, jpg, and jpeg.', skipValidation: true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('deviceForLocal')
            ->callback($this->action(...));
    }

    public function action(
        mixed $file,
        Request $request,
        Response $response,
        Database $dbForProject,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
        Device $deviceForLocal,
    ): void {
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
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
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, 'Photo must be a PNG or JPEG image');
        }

        $custom = new Custom($deviceForFiles);
        $userId = $user->getId();
        $previous = $user->getAttribute('photoId', '');
        $photoId = ID::unique();

        $deviceForFiles->upload($deviceForLocal->read($fileTmpName), $custom->getPath($userId, $photoId), $mimeType);

        try {
            $user = $dbForProject->updateDocument('users', $userId, new Document([
                'photoId' => $photoId,
                'photoSize' => $size,
            ]));
        } catch (\Throwable $th) {
            $custom->delete($userId, $photoId);

            throw $th;
        }

        // A file left behind here, or by a racing request, is removed with the user's photo folder
        if ($previous !== '' && !$custom->delete($userId, $previous)) {
            Console::warning('Failed to remove previous photo ' . $previous);
        }

        $queueForEvents->setParam('userId', $userId);

        $response->dynamic($user, Response::MODEL_USER);
    }
}
