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
            ->label('scope', ['videos.play', 'videos.read'])
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
        $video = self::getAuthorizedVideo($dbForProject, $authorization, $user, $videoId);

        $rendition = $authorization->skip(fn () => $dbForProject->getDocument('videosRenditions', $renditionId));

        // Same gate as the media playlist: segment rows are written before the
        // rendition reaches `ready`, and those bytes must stay unpublished.
        // The URL output has to be this rendition's output, or the response
        // labels the other container's bytes with this route's content type.
        if (
            $rendition->isEmpty()
            || $rendition->getAttribute('videoInternalId') !== $video->getSequence()
            || $rendition->getAttribute('status') !== self::STATUS_READY
            || $rendition->getAttribute('output') !== $output
        ) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $segment = $authorization->skip(fn () => $dbForProject->getDocument('videosRenditionsSegments', $segmentId));

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
            // Permission-gated. A shared cache would keep serving this URL after
            // access is revoked or the video is deleted, and nothing can purge it.
            // The bytes never change, so a private cache may hold them.
            ->addHeader('Cache-Control', 'private, max-age=31536000, immutable')
            ->addHeader('Accept-Ranges', 'bytes')
            ->addHeader('X-Peak', \memory_get_peak_usage());

        $rangeHeader = $request->getHeaderLine('range');

        if (!empty($rangeHeader)) {
            [$unit, $spec] = \array_pad(\explode('=', $rangeHeader, 2), 2, '');
            $suffix = \substr($spec, 1);

            // A suffix range (bytes=-N) asks for the last N bytes. The request
            // parser rejects it, since it has no first-byte-pos.
            $suffixed = \str_starts_with($spec, '-') && \ctype_digit($suffix);
            if ($suffixed) {
                $start = \max(0, $size - (int) $suffix);
                $end = $size - 1;
            } else {
                $start = $request->getRangeStart();
                $end = $request->getRangeEnd();
                $unit = $request->getRangeUnit();
            }

            if ($unit !== 'bytes' || $start === null) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            // RFC 9110: an unsatisfiable range answers 416 with the full length,
            // so the client can retry inside it. This also covers bytes=-0 and
            // any range against an empty segment.
            if ($start >= $size) {
                $response->addHeader('Content-Range', 'bytes */' . $size);
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            if ($end === null || ($end - $start + 1)> APP_STORAGE_READ_BUFFER) {
                // A suffix asks for the tail, so the cap moves the start and
                // keeps the end at the segment boundary.
                if ($suffixed) {
                    $start = $end - MAX_OUTPUT_CHUNK_SIZE + 1;
                } else {
                    $end = $start + MAX_OUTPUT_CHUNK_SIZE - 1;
                }
            }

            // RFC 9110: a last-byte-pos past the end of the file is clamped, not
            // rejected. Players request a window such as bytes=0-2000000 against
            // a much smaller segment; that span stays under the read buffer.
            $end = \min($end, $size - 1);

            $response
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT)
                ->send((string) $deviceForVideos->read($path, $start, ($end - $start + 1)));

            return;
        }

        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < \ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    (string) $deviceForVideos->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        \min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }

            return;
        }

        $response->send((string) $deviceForVideos->read($path));
    }
}
