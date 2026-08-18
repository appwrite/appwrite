<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Subtitles\Segments;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Validator\WhiteList;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getSubtitleSegment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/outputs/:output/subtitles/:subtitleId/segments/:segmentId')
            ->desc('Get subtitle segment')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'playback',
                name: 'getSubtitleSegment',
                description: '/docs/references/videos/get-subtitle-segment.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::ANY,
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('output', '', new WhiteList(self::OUTPUTS, true), 'Streaming output format.', enum: new Enum(name: 'VideoOutput'))
            ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
            ->param('segmentId', '', new UID(), 'Segment unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('deviceForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $output,
        string $subtitleId,
        string $segmentId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $subtitle = $authorization->skip(fn () => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty() || $subtitle->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        $segment = $authorization->skip(fn () => $dbForProject->getDocument('videos_subtitles_segments', $segmentId));

        if ($segment->isEmpty() || $segment->getAttribute('subtitleInternalId') !== $subtitle->getSequence()) {
            throw new Exception(Exception::VIDEO_SUBTITLE_SEGMENT_NOT_FOUND);
        }

        $path = $segment->getAttribute('path', '') . $segment->getAttribute('fileName', '');

        if (!$deviceForVideos->exists($path)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_SEGMENT_NOT_FOUND);
        }

        $response
            ->setContentType('text/vtt')
            ->addHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->send((string) $deviceForVideos->read($path));
    }
}
