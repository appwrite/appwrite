<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Renditions\Segments;

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
        return 'getSegment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/outputs/:output/renditions/:renditionId/segments/:segmentId')
            ->desc('Get segment')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'playback',
                name: 'getSegment',
                description: '/docs/references/videos/get-segment.md',
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
            ->param('renditionId', '', new UID(), 'Rendition unique ID.')
            ->param('segmentId', '', new UID(), 'Segment unique ID.')
            ->inject('request')
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
        string $renditionId,
        string $segmentId,
        Request $request,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos
    ): void {
        // The pre-merge endpoint ran no permission check at all and never verified
        // the segment belonged to the requested video or rendition, so any segment
        // id in the project was readable by anyone.
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        $rendition = $authorization->skip(fn () => $dbForProject->getDocument('videos_renditions', $renditionId));

        if ($rendition->isEmpty() || $rendition->getAttribute('videoInternalId') !== $video->getSequence()) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $segment = $authorization->skip(fn () => $dbForProject->getDocument('videos_renditions_segments', $segmentId));

        if ($segment->isEmpty() || $segment->getAttribute('renditionInternalId') !== $rendition->getSequence()) {
            throw new Exception(Exception::VIDEO_RENDITION_SEGMENT_NOT_FOUND);
        }

        $path = $segment->getAttribute('path', '') . $segment->getAttribute('fileName', '');

        if (!$deviceForVideos->exists($path)) {
            throw new Exception(Exception::VIDEO_RENDITION_SEGMENT_NOT_FOUND);
        }

        $contentType = $output === self::OUTPUT_HLS ? 'video/mp2t' : 'video/iso.segment';
        $size = $deviceForVideos->getFileSize($path);

        $response
            ->setContentType($contentType)
            // Segments are immutable once written, so they can be cached hard.
            ->addHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->addHeader('Accept-Ranges', 'bytes')
            ->addHeader('X-Peak', \memory_get_peak_usage());

        $rangeHeader = $request->getHeaderLine('range');

        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null || $end - $start > APP_STORAGE_READ_BUFFER) {
                $end = \min(($start + MAX_OUTPUT_CHUNK_SIZE - 1), ($size - 1));
            }

            if ($unit !== 'bytes' || $start >= $end || $end >= $size) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT)
                ->send($deviceForVideos->read($path, $start, ($end - $start + 1)));

            return;
        }

        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < \ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceForVideos->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        \min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }

            return;
        }

        $response->send($deviceForVideos->read($path));
    }
}
