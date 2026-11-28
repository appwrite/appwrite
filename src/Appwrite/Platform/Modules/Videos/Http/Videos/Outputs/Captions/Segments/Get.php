<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Captions\Segments;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getCaptionSegment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/outputs/:output/captions/:captionId/segments/:segmentId')
            ->desc('Get caption segment')
            ->groups(['api', 'videos'])
            ->label('scope', ['videos.play', 'videos.read'])
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'playback',
                name: 'getCaptionSegment',
                // Players follow this URL from the manifest; apps never call it directly. Preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
                description: '/docs/references/videos/get-caption-segment.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::ANY,
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'Environment', 'ImpersonateUserId'],
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('output', '', new WhiteList(self::OUTPUTS, true), 'Streaming output format.', enum: new Enum(name: 'VideoOutput'))
            ->param('captionId', '', new UID(), 'Caption unique ID.')
            ->param('segmentId', '', new UID(), 'Segment unique ID.')
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->inject('deviceForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        string $output,
        string $captionId,
        string $segmentId,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos
    ): void {
        $video = self::getAuthorizedVideo($dbForProject, $authorization, $user, $videoId);

        $caption = $authorization->skip(fn () => $dbForProject->getDocument('videosCaptions', $captionId));

        if ($caption->isEmpty() || $caption->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_CAPTION_NOT_FOUND);
        }

        $segment = $authorization->skip(fn () => $dbForProject->getDocument('videosCaptionsSegments', $segmentId));

        if ($segment->isEmpty() || $segment->getAttribute('captionInternalId') !== $caption->getSequence()) {
            throw new Exception(Exception::VIDEO_CAPTION_SEGMENT_NOT_FOUND);
        }

        $path = $segment->getAttribute('path', '') . $segment->getAttribute('fileName', '');

        if (!$deviceForVideos->exists($path)) {
            throw new Exception(Exception::VIDEO_CAPTION_SEGMENT_NOT_FOUND);
        }

        // A public video may sit at Fastly under the video Surrogate-Key.
        // A private one stays on the short revalidation window: the browser
        // cache is not cleared by a Fastly purge, so a month-long immutable
        // copy would keep playing after read access is revoked. The stable
        // DASH WebVTT path uses that same short policy.
        $response->setContentType('text/vtt');
        if ($this->isPublicRead($video)) {
            $this->cachePlaybackSegment($response, $project, $video);
        } elseif ($this->captionBytesNotModified($request, $response, $caption)) {
            return;
        }

        $response->send((string) $deviceForVideos->read($path));
    }
}
