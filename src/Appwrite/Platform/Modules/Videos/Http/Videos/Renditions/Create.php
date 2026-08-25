<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Renditions;

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
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createRendition';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/videos/:videoId/renditions')
            ->desc('Create rendition')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].renditions.[renditionId].create')
            ->label('audits.event', 'rendition.create')
            ->label('audits.resource', 'video/{request.videoId}/rendition/{response.$id}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'renditions',
                name: 'createRendition',
                description: '/docs/references/videos/create-rendition.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_VIDEO_RENDITION,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('profileId', '', new UID(), 'Video profile unique ID to encode against.')
            ->param('output', '', new WhiteList(self::OUTPUTS, true), 'Streaming output format to package as.', enum: new Enum(name: 'VideoOutput'))
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
        string $videoId,
        string $profileId,
        string $output,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Event $queueForEvents,
        VideoPublisher $publisherForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $profile = $authorization->skip(fn () => $dbForProject->getDocument('videos_profiles', $profileId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $width = (int) $profile->getAttribute('width', 0);
        $height = (int) $profile->getAttribute('height', 0);
        $videoBitRate = (int) $profile->getAttribute('videoBitRate', 0);
        $audioBitRate = (int) $profile->getAttribute('audioBitRate', 0);

        // Created up front with status `waiting` rather than returning a bare 204,
        // so the caller has an id to poll and the worker always has a document to
        // report failure on.
        $rendition = $authorization->skip(fn () => $dbForProject->createDocument('videos_renditions', new Document([
            '$id' => ID::unique(),
            'videoId' => $video->getId(),
            'videoInternalId' => $video->getSequence(),
            'profileId' => $profile->getId(),
            'profileInternalId' => $profile->getSequence(),
            'name' => $width . 'X' . $height . '@' . ($videoBitRate + $audioBitRate),
            'width' => $width,
            'height' => $height,
            'videoBitRate' => $videoBitRate,
            'audioBitRate' => $audioBitRate,
            'output' => $output,
            'status' => self::STATUS_WAITING,
            'progress' => '0',
        ])));

        // Insert-then-read: if download already flipped the video to ready, start
        // encoding now; otherwise the download job's ready-then-scan will pick
        // this waiting row up.
        $video = $authorization->skip(fn () => $dbForProject->getDocument('videos', $video->getId()));

        $publisherForVideos->enqueue(new VideoMessage(
            project: $project,
            action: $video->getAttribute('status') === self::STATUS_READY
                ? VideoAction::Encode
                : VideoAction::Download,
            video: $video,
            profile: $profile,
            rendition: $rendition,
            output: $output,
        ));

        $queueForEvents
            ->setParam('videoId', $video->getId())
            ->setParam('renditionId', $rendition->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($rendition, Response::MODEL_VIDEO_RENDITION);
    }
}
