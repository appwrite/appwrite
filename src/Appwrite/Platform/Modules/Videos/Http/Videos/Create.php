<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Video as VideoPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createVideo';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/videos')
            ->desc('Create video')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].create')
            ->label('audits.event', 'video.create')
            ->label('audits.resource', 'video/{response.$id}')
            ->label('usage.resource', 'video/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'createVideo',
                description: '/docs/references/videos/create-video.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VIDEO,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID holding the source file.')
            ->param('fileId', '', new UID(), 'Source file unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->inject('publisherForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Event $queueForEvents,
        VideoPublisher $publisherForVideos
    ): void {
        $file = $this->assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);

        $mimeType = $file->getAttribute('mimeType', '');
        $isSupported = \in_array($mimeType, self::SOURCE_MIME_TYPES, true);

        foreach (self::SOURCE_MIME_PREFIXES as $prefix) {
            if (\str_starts_with($mimeType, $prefix)) {
                $isSupported = true;
                break;
            }
        }

        if (!$isSupported) {
            throw new Exception(Exception::VIDEO_NOT_VALID);
        }

        // Video documents are project-internal: access is always derived from the
        // bucket/file they point at, so they carry no permissions of their own.
        $video = $authorization->skip(fn () => $dbForProject->createDocument('videos', new Document([
            '$id' => ID::unique(),
            'bucketId' => $file->getAttribute('bucketId', $bucketId),
            'bucketInternalId' => $file->getAttribute('bucketInternalId', ''),
            'fileId' => $file->getId(),
            'fileInternalId' => $file->getSequence(),
            'size' => $file->getAttribute('sizeOriginal', 0),
            'status' => self::STATUS_WAITING,
            'chunksTotal' => self::chunkCount((int) $file->getAttribute('sizeOriginal', 0)),
            'chunksUploaded' => 0,
            'search' => \implode(' ', [$file->getId(), $file->getAttribute('name', '')]),
        ])));

        $publisherForVideos->enqueue(new VideoMessage(
            project: $project,
            action: VideoAction::Download,
            video: $video,
        ));

        $queueForEvents->setParam('videoId', $video->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($video, Response::MODEL_VIDEO);
    }
}
