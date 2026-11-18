<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams\Photo;

use Appwrite\Abuse\Limit;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request\Validator\File;
use Appwrite\Utopia\Response;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
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
        'webp',
    ];

    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    public static function getName(): string
    {
        return 'updateTeamPhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/teams/:teamId/photo')
            ->desc('Update photo')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].update')
            ->label('scope', 'teams.write')
            ->label('audits.event', 'team.update')
            ->label('audits.resource', 'team/{request.teamId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'updatePhoto',
                description: <<<'EOT'
                Update the photo of a team. Updating an already customized photo replaces it. The image must be at most 5MB and is sent in a single request.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TEAM,
                    ),
                ],
                requestType: ContentType::MULTIPART,
                type: MethodType::UPLOAD,
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
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
        string $teamId,
        mixed $file,
        Request $request,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Device $deviceForFiles,
        Device $deviceForLocal,
    ): void {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        // Photos fit in one chunk, so a chunked upload is refused rather than stored as a partial image
        if (!empty($request->getHeaderLine('content-range'))) {
            throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE, 'Photo must be sent in a single request');
        }

        $file = $request->getFiles('file');


        $response->dynamic($updated, Response::MODEL_TEAM);
    }
}
