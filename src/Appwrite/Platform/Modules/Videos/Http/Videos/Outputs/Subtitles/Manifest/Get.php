<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Subtitles\Manifest;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Manifest\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
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
        return 'getSubtitleManifest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/outputs/:output/subtitles/:subtitleId/manifest')
            ->desc('Get subtitle manifest')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'playback',
                name: 'getSubtitleManifest',
                description: '/docs/references/videos/get-subtitle-manifest.md',
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
        string $subtitleId,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos
    ): void {
        $video = $this->authorizeVideo($dbForProject, $authorization, $user, $videoId);

        $subtitle = $authorization->skip(fn () => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if (
            $subtitle->isEmpty()
            || $subtitle->getAttribute('videoInternalId') !== $video->getSequence()
            || $subtitle->getAttribute('status') !== self::STATUS_READY
        ) {
            throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        // DASH addresses the WebVTT file directly from the MPD's <BaseURL>, so this
        // route serves the file itself rather than a playlist. The pre-merge version
        // sent it and then fell through into the HLS branch, throwing after the
        // response was already committed.
        if ($output === self::OUTPUT_DASH) {
            $path = $subtitle->getAttribute('path', '');

            if (empty($path) || !$deviceForVideos->exists($path)) {
                throw new Exception(Exception::VIDEO_SUBTITLE_NOT_FOUND);
            }

            $response
                ->setContentType('text/vtt')
                ->addHeader('Cache-Control', 'public, max-age=31536000, immutable')
                ->send((string) $deviceForVideos->read($path));

            return;
        }

        $segments = $authorization->skip(fn () => $dbForProject->find('videos_subtitles_segments', [
            Query::equal('subtitleInternalId', [$subtitle->getSequence()]),
            Query::orderAsc('$sequence'),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        if (empty($segments)) {
            throw new Exception(Exception::VIDEO_SUBTITLE_SEGMENT_NOT_FOUND);
        }

        $baseUri = $this->baseUri($video, $output) . '/subtitles/' . $subtitle->getId() . '/segments/';

        $entries = [];

        foreach ($segments as $segment) {
            $entries[] = [
                'duration' => $segment->getAttribute('duration', 0),
                'url' => $this->withProject($baseUri . $segment->getId(), $project),
            ];
        }

        $manifest = $this->renderView('hls-subtitles', [
            'targetDuration' => $subtitle->getAttribute('targetDuration', 0),
            'segments' => $entries,
        ]);

        $this->sendManifest($response, $manifest, 'application/x-mpegurl');
    }
}
