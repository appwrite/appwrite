<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\HLS\Manifest;

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
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getHlsManifest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/outputs/hls/master.m3u8')
            ->desc('Get HLS manifest')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'playback',
                name: 'getHlsManifest',
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

        $renditions = $this->getReadyRenditions($dbForProject, $authorization, $video, self::OUTPUT_HLS);
        $subtitles = $this->getReadySubtitles($dbForProject, $authorization, $video);

        $baseUri = $this->baseUri($video, self::OUTPUT_HLS);

        $audios = [];
        $streams = [];
        $seenAudioLanguages = [];

        // Two passes: collect alternate-audio groups first so every video
        // STREAM-INF can reference them, and so CODECS can drop muxed-audio
        // entries that would make players expect audio inside the video playlist.
        foreach ($renditions as $rendition) {
            $metadata = $rendition->getAttribute('metadata', []);

            foreach ($metadata['hls'] ?? [] as $stream) {
                if (($stream['type'] ?? '') !== 'audio') {
                    continue;
                }

                // One EXT-X-MEDIA entry per language: a multi-rendition ladder
                // repeats the same audio track at every video quality.
                $language = $stream['language'] ?? null;

                if ($language !== null && isset($seenAudioLanguages[$language])) {
                    continue;
                }

                if ($language !== null) {
                    $seenAudioLanguages[$language] = true;
                }

                $audios[] = [
                    'name' => $stream['name'] ?? $language,
                    'language' => $language,
                    'uri' => $this->withProject(
                        $baseUri . '/renditions/' . $rendition->getId() . '/streams/' . ($stream['id'] ?? 0) . '/playlist.m3u8',
                        $project
                    ),
                ];
            }
        }

        $hasAudioGroup = !empty($audios);

        foreach ($renditions as $rendition) {
            $metadata = $rendition->getAttribute('metadata', []);

            foreach ($metadata['hls'] ?? [] as $stream) {
                if (($stream['type'] ?? '') === 'audio') {
                    continue;
                }

                $codecs = $stream['codecs'] ?? null;
                if ($hasAudioGroup && \is_string($codecs)) {
                    $parts = \array_values(\array_filter(
                        \array_map('trim', \explode(',', $codecs)),
                        fn (string $part) => $part !== '' && !\str_starts_with($part, 'mp4a')
                    ));
                    $codecs = empty($parts) ? null : \implode(',', $parts);
                }

                $streams[] = [
                    'bandwidth' => $stream['bandwidth'] ?? 0,
                    'resolution' => $stream['resolution'] ?? '',
                    'name' => $rendition->getAttribute('name', ''),
                    'codecs' => $codecs,
                    'subs' => empty($subtitles) ? null : 'subs',
                    'audio' => $hasAudioGroup ? 'group_audio' : null,
                    'uri' => $this->withProject(
                        $baseUri . '/renditions/' . $rendition->getId() . '/streams/' . ($stream['id'] ?? 0) . '/playlist.m3u8',
                        $project
                    ),
                ];
            }
        }

        $subtitleEntries = [];

        foreach ($subtitles as $subtitle) {
            $subtitleEntries[] = [
                'name' => $subtitle->getAttribute('name', ''),
                'code' => $subtitle->getAttribute('code', ''),
                'default' => $subtitle->getAttribute('default', false) ? 'YES' : 'NO',
                'uri' => $this->withProject($baseUri . '/subtitles/' . $subtitle->getId() . '/manifest', $project),
            ];
        }

        $manifest = $this->renderView('hls-master', [
            'audios' => $audios,
            'subtitles' => $subtitleEntries,
            'renditions' => $streams,
        ]);

        $this->sendManifest($response, $manifest, 'application/x-mpegurl');
    }
}
