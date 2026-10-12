<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Timeline;

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
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getTimeline';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/timeline')
            ->desc('Get timeline')
            ->groups(['api', 'videos'])
            ->label('scope', ['videos.play', 'videos.read'])
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'getTimeline',
                description: '/docs/references/videos/get-timeline.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::TEXT,
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
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
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos
    ): void {
        $video = self::getAuthorizedVideo($dbForProject, $authorization, $user, $videoId);

        // Scoped to this video's sprite sheets. The cue file alone is not enough:
        // during a rebuild only timeline.vtt remains until the sheets are rewritten.
        $sprites = $authorization->skip(fn () => $dbForProject->find('videos_timelines', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::notEqual('name', ['timeline.vtt']),
            Query::limit(1),
        ]));

        if (empty($sprites)) {
            throw new Exception(Exception::VIDEO_TIMELINE_NOT_FOUND);
        }

        $path = $deviceForVideos->getPath($video->getId() . '/timeline') . '/timeline.vtt';

        if (!$deviceForVideos->exists($path)) {
            throw new Exception(Exception::VIDEO_TIMELINE_NOT_FOUND);
        }

        // Disk stores bare `previews/{id}#xywh=...`. Players resolve those
        // without inheriting the timeline's ?project=, and cannot send the
        // project header, so every cue URL must carry the query itself.
        $vtt = $this->withProjectOnPreviewCues((string) $deviceForVideos->read($path), $project);

        $response
            ->setContentType('text/vtt')
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->send($vtt);
    }

    /**
     * Appends `?project=` to each `previews/{id}` cue URI, before any `#xywh=`
     * fragment. The on-disk VTT is left unchanged.
     */
    private function withProjectOnPreviewCues(string $vtt, Document $project): string
    {
        $lines = \explode("\n", $vtt);

        foreach ($lines as $index => $line) {
            $trimmed = \rtrim($line, "\r");

            if (!\str_starts_with($trimmed, 'previews/')) {
                continue;
            }

            $fragment = '';
            $hash = \strpos($trimmed, '#');
            if ($hash !== false) {
                $fragment = \substr($trimmed, $hash);
                $trimmed = \substr($trimmed, 0, $hash);
            }

            $lines[$index] = $this->withProject($trimmed, $project) . $fragment;
        }

        return \implode("\n", $lines);
    }
}
