<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\DASH\Manifest;

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
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getDashManifest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/outputs/dash/master.mpd')
            ->desc('Get DASH manifest')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'playback',
                name: 'getDashManifest',
                description: '/docs/references/videos/get-manifest.md',
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
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->authorizeVideo($dbForProject, $authorization, $user, $videoId);

        $renditions = $this->getReadyRenditions($dbForProject, $authorization, $video, self::OUTPUT_DASH);
        $subtitles = $this->getReadySubtitles($dbForProject, $authorization, $video);

        $baseUri = $this->baseUri($video, self::OUTPUT_DASH);

        $mpd = [];
        $adaptations = [];

        foreach ($renditions as $rendition) {
            $metadata = $rendition->getAttribute('metadata', []);
            $parsed = $metadata['mpd'] ?? null;

            if (empty($parsed)) {
                continue;
            }

            // Presentation-level attributes are identical across renditions of the
            // same video, so the last one wins.
            $mpd = $parsed['attributes'] ?? $mpd;

            foreach ($parsed['adaptations'] ?? [] as $adaptation) {
                $streamId = (int) ($adaptation['id'] ?? 0);

                $segments = $authorization->skip(fn () => $dbForProject->find('videos_renditions_segments', [
                    Query::equal('renditionInternalId', [$rendition->getSequence()]),
                    Query::equal('streamId', [$streamId]),
                    Query::orderAsc('$sequence'),
                    Query::limit(APP_LIMIT_SUBQUERY),
                ]));

                $init = '';
                $media = [];

                foreach ($segments as $segment) {
                    // Relative to <BaseURL>, which is what the MPD resolves against.
                    $uri = $this->withProject($segment->getId(), $project);

                    if ((int) $segment->getAttribute('isInit', 0) === 1) {
                        $init = $uri;
                        continue;
                    }

                    $media[] = $uri;
                }

                $representation = $adaptation['representation'] ?? [];
                $representation['segmentList'] = ($representation['segmentList'] ?? []) + ['attributes' => []];
                $representation['segmentList']['init'] = $init;
                $representation['segmentList']['media'] = $media;

                $adaptations[] = [
                    'id' => $streamId,
                    'attributes' => $adaptation['attributes'] ?? [],
                    'representation' => $representation,
                    // Trailing slash matters: the init/media values above are resolved
                    // against this as a directory.
                    'baseUrl' => $baseUri . '/renditions/' . $rendition->getId() . '/segments/',
                ];
            }
        }

        if (empty($adaptations)) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $subtitleEntries = [];

        foreach ($subtitles as $subtitle) {
            $subtitleEntries[] = [
                'id' => $subtitle->getId(),
                'name' => $subtitle->getAttribute('code', ''),
                'baseUrl' => $this->withProject($baseUri . '/subtitles/' . $subtitle->getId() . '/manifest', $project),
            ];
        }

        $manifest = $this->renderView('dash', [
            'mpd' => $mpd,
            'renditions' => $adaptations,
            'subtitles' => $subtitleEntries,
        ]);

        $this->sendManifest($response, $manifest, 'application/dash+xml');
    }
}
