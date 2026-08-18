<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Manifest;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base as VideosAction;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * Shared behaviour for the master-manifest and stream-playlist endpoints.
 *
 * HLS, DASH, and CMAF get their own routes rather than one `:output` route because
 * the master manifest is the single URL an application hands straight to a player,
 * and Android's ExoPlayer (`Util.inferContentType`) and iOS `AVURLAsset` both
 * infer the container from the URI extension. Keeping `master.m3u8`/`master.mpd`
 * in the path means no client needs an explicit mime-type override.
 *
 * `Base.php` inside an `Http/` directory follows the precedent set by
 * `Modules/Console/Http/Redirects/Base.php` and
 * `Modules/Health/Http/Health/Queue/Base.php`.
 */
abstract class Base extends VideosAction
{
    /**
     * Ready renditions for this video and output, or throws when there are none.
     *
     * @return array<Document>
     */
    protected function getReadyRenditions(
        Database $dbForProject,
        Authorization $authorization,
        Document $video,
        string $output
    ): array {
        $renditions = $authorization->skip(fn () => $dbForProject->find('videos_renditions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('output', [$output]),
            Query::equal('status', [self::STATUS_READY]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        if (empty($renditions)) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        return $renditions;
    }

    /**
     * Ready subtitle tracks for this video.
     *
     * @return array<Document>
     */
    protected function getReadySubtitles(
        Database $dbForProject,
        Authorization $authorization,
        Document $video
    ): array {
        return $authorization->skip(fn () => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [self::STATUS_READY]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));
    }

    /**
     * Root for every URL inside a manifest.
     *
     * An absolute path rather than an absolute URL: the pre-merge code built these
     * from a hard-coded `TMP_HOST` constant ('http://127.0.0.1/'), which broke
     * behind any proxy, custom domain or TLS terminator. A path is resolved by the
     * player against the manifest's own scheme and authority, so it is correct
     * everywhere without configuration.
     */
    protected function baseUri(Document $video, string $output): string
    {
        return '/v1/videos/' . $video->getId() . '/outputs/' . $output;
    }

    /**
     * Playback routes authenticate via a `project` query parameter (see the
     * `locationAuth` on each SDK method), so every URL a player follows out of a
     * manifest has to carry it forward or the next request has no project context.
     */
    protected function withProject(string $uri, Document $project): string
    {
        return $uri . (\str_contains($uri, '?') ? '&' : '?') . 'project=' . \urlencode($project->getId());
    }

    /**
     * Renders a template from `app/views/videos/` and sends it with an explicit
     * content type.
     */
    protected function sendManifest(Response $response, string $body, string $contentType): void
    {
        $response
            ->setContentType($contentType)
            // Manifests change as renditions come and go, so they must not be cached
            // the way immutable segments are.
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->send($body);
    }

    /**
     * Loads the video and asserts the caller may read its source file.
     */
    protected function authorizeVideo(
        Database $dbForProject,
        Authorization $authorization,
        User $user,
        string $videoId
    ): Document {
        return $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);
    }

    /**
     * Build and send an HLS (or CMAF-HLS) master playlist for ready renditions.
     */
    protected function sendHlsMaster(
        string $videoId,
        string $output,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->authorizeVideo($dbForProject, $authorization, $user, $videoId);

        $renditions = $this->getReadyRenditions($dbForProject, $authorization, $video, $output);
        $subtitles = $this->getReadySubtitles($dbForProject, $authorization, $video);

        $baseUri = $this->baseUri($video, $output);

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

    /**
     * Build and send a DASH (or CMAF-DASH) MPD for ready renditions.
     */
    protected function sendDashMaster(
        string $videoId,
        string $output,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->authorizeVideo($dbForProject, $authorization, $user, $videoId);

        $renditions = $this->getReadyRenditions($dbForProject, $authorization, $video, $output);
        $subtitles = $this->getReadySubtitles($dbForProject, $authorization, $video);

        $baseUri = $this->baseUri($video, $output);

        $mpd = [];
        $adaptations = [];
        $adaptationSeq = 0;

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

                // Older encodes may lack SegmentList@duration in metadata; derive it so
                // dash.js can schedule list-addressed segments.
                $segAttrs = $representation['segmentList']['attributes'] ?? [];
                if (empty($segAttrs['duration'])) {
                    $timescale = (int) ($segAttrs['timescale'] ?? 0);
                    $target = (float) $rendition->getAttribute('targetDuration', 0);
                    if ($timescale <= 0) {
                        $timescale = 1000000;
                        $segAttrs['timescale'] = (string) $timescale;
                    }
                    if ($target > 0) {
                        $segAttrs['duration'] = (string) (int) \round($target * $timescale);
                    } elseif (\count($media) > 0) {
                        $presentation = (string) ($mpd['mediaPresentationDuration'] ?? '');
                        // PT32.8S → seconds; split evenly across media segments.
                        if (\preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?/', $presentation, $m)) {
                            $seconds = ((int) ($m[1] ?? 0)) * 3600
                                + ((int) ($m[2] ?? 0)) * 60
                                + (float) ($m[3] ?? 0);
                            if ($seconds > 0) {
                                $segAttrs['duration'] = (string) (int) \round(($seconds / \count($media)) * $timescale);
                            }
                        }
                    }
                    $representation['segmentList']['attributes'] = $segAttrs;
                }

                // Each Appwrite rendition is packed alone, so stream indexes restart at 0.
                // AdaptationSet/@id must still be unique across the merged master or
                // players (dash.js) collapse qualities into a single track.
                $adaptationId = $adaptationSeq++;
                $representation['attributes'] = ($representation['attributes'] ?? []);
                $representation['attributes']['id'] = (string) ($representation['attributes']['id'] ?? $streamId)
                    . '-' . $rendition->getId();

                $adaptations[] = [
                    'id' => $adaptationId,
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

    /**
     * Build and send an HLS media playlist for one stream of a ready rendition.
     *
     * When the stream has an fMP4 initialisation segment (CMAF), the playlist
     * includes `#EXT-X-MAP` so players can decode media segments.
     */
    protected function sendStreamPlaylist(
        string $videoId,
        string $renditionId,
        int $streamId,
        string $output,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->authorizeVideo($dbForProject, $authorization, $user, $videoId);

        // Loaded by id and then checked against the video, rather than the
        // pre-merge `Query::equal('_uid', ...)` which reached for a raw internal
        // column and never verified the rendition belonged to this video.
        $rendition = $authorization->skip(fn () => $dbForProject->getDocument('videos_renditions', $renditionId));

        if (
            $rendition->isEmpty()
            || $rendition->getAttribute('videoInternalId') !== $video->getSequence()
            || $rendition->getAttribute('status') !== self::STATUS_READY
        ) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $segments = $authorization->skip(fn () => $dbForProject->find('videos_renditions_segments', [
            Query::equal('renditionInternalId', [$rendition->getSequence()]),
            Query::equal('streamId', [$streamId]),
            Query::orderAsc('$sequence'),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        if (empty($segments)) {
            throw new Exception(Exception::VIDEO_RENDITION_SEGMENT_NOT_FOUND);
        }

        $baseUri = $this->baseUri($video, $output)
            . '/renditions/' . $rendition->getId() . '/segments/';

        $map = null;
        $entries = [];

        foreach ($segments as $segment) {
            if ((int) $segment->getAttribute('isInit', 0) === 1) {
                $map = $this->withProject($baseUri . $segment->getId(), $project);
                continue;
            }

            $entries[] = [
                'duration' => $segment->getAttribute('duration', 0),
                'url' => $this->withProject($baseUri . $segment->getId(), $project),
            ];
        }

        $manifest = $this->renderView('hls', [
            'targetDuration' => $rendition->getAttribute('targetDuration', 0),
            'map' => $map,
            'segments' => $entries,
        ]);

        $this->sendManifest($response, $manifest, 'application/x-mpegurl');
    }
}
