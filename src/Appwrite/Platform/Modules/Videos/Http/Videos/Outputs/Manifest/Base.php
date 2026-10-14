<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Manifest;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base as VideosAction;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Appwrite\Video\Dash;
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
     * Never mix codecs in one ABR playlist. Prefer h264 when present; otherwise
     * serve the single remaining codec.
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

        $byCodec = [];
        foreach ($renditions as $rendition) {
            $codec = self::normalizeCodec($rendition->getAttribute('codec'));
            $byCodec[$codec][] = $rendition;
        }

        if (isset($byCodec[self::CODEC_H264])) {
            return $byCodec[self::CODEC_H264];
        }

        // $renditions was non-empty, so a codec bucket exists.
        return \reset($byCodec);
    }

    /**
     * Ready caption tracks for this video.
     *
     * @return array<Document>
     */
    protected function getReadyCaptions(
        Database $dbForProject,
        Authorization $authorization,
        Document $video
    ): array {
        return $authorization->skip(fn () => $dbForProject->find('videos_captions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [self::STATUS_READY]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));
    }

    /**
     * Every segment matching `$filters`, ordered by `$sequence`.
     *
     * Playlists must ship the full ladder — a single `APP_LIMIT_SUBQUERY` page
     * would truncate a long encode (~100 min at 6s segments). Count first, find
     * with that limit, and refuse a partial page rather than serve a corrupt
     * playlist. No cursor pagination: callers need the entire ordered list.
     *
     * @param array<Query> $filters Equality filters only (no limit/order/cursor)
     * @return array<Document>
     */
    protected function getSegments(
        Database $dbForProject,
        Authorization $authorization,
        string $collection,
        array $filters
    ): array {
        $total = $authorization->skip(fn () => $dbForProject->count($collection, $filters));
        if ($total === 0) {
            return [];
        }

        $segments = $authorization->skip(fn () => $dbForProject->find($collection, [
            ...$filters,
            Query::orderAsc('$sequence'),
            Query::limit($total),
        ]));

        if (\count($segments) !== $total) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR);
        }

        return $segments;
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
     * Renders a template from `app/views/videos/` and sends it with an explicit
     * content type.
     */
    protected function sendManifest(Response $response, string $body, string $contentType): void
    {
        $response
            ->setContentType($contentType)
            // Manifests change as renditions come and go, so they must not be cached
            // the way segment bytes are.
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->send($body);
    }

    /**
     * Loads the video enforcing document `$permissions` for sessions.
     */
    protected function authorizeVideo(
        Database $dbForProject,
        Authorization $authorization,
        User $user,
        string $videoId
    ): Document {
        return self::getAuthorizedVideo($dbForProject, $authorization, $user, $videoId);
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
        $captions = $this->getReadyCaptions($dbForProject, $authorization, $video);

        $baseUri = $this->baseUri($video, $output);

        $audios = [];
        $streams = [];
        $seenAudio = [];

        // Two passes: collect alternate-audio groups first so every video
        // STREAM-INF can reference them. ffmpeg's STREAM-INF CODECS only lists
        // the video codec when audio is demuxed into an EXT-X-MEDIA group;
        // Safari still requires the group's audio codec (AAC → mp4a.40.2).
        foreach ($renditions as $rendition) {
            $metadata = $rendition->getAttribute('metadata', []);

            foreach ($metadata['hls'] ?? [] as $stream) {
                if (($stream['type'] ?? '') !== 'audio') {
                    continue;
                }

                // One EXT-X-MEDIA entry per source track. A ladder repeats that
                // track at every quality, and language is not an identity:
                // several streams can share a tag, and streams with none all
                // share the empty key. The packaged stream id is the track —
                // every rendition lists the source tracks in the same order.
                $language = $stream['language'] ?? null;
                if (!\is_string($language) || $language === '') {
                    $language = null;
                }

                $trackId = (string) ($stream['id'] ?? '');
                // A missing id cannot tell tracks apart, so fall back to language.
                // Prefix the key so a numeric id is not coerced onto an integer slot.
                $key = $trackId !== '' ? 'id:' . $trackId : 'lang:' . ($language ?? '');

                if (isset($seenAudio[$key])) {
                    continue;
                }

                $seenAudio[$key] = true;

                $name = $stream['name'] ?? $language;
                if (!\is_string($name) || $name === '') {
                    $name = null;
                }

                $audios[] = [
                    'name' => $name,
                    'language' => $language,
                    'track' => $trackId,
                    'uri' => $this->withProject(
                        $baseUri . '/renditions/' . $rendition->getId() . '/streams/' . ($stream['id'] ?? 0) . '/playlist.m3u8',
                        $project
                    ),
                ];
            }
        }

        $this->distinctAudioNames($audios);

        $hasAudioGroup = !empty($audios);

        foreach ($renditions as $rendition) {
            $metadata = $rendition->getAttribute('metadata', []);

            foreach ($metadata['hls'] ?? [] as $stream) {
                if (($stream['type'] ?? '') === 'audio') {
                    continue;
                }

                $codecs = $stream['codecs'] ?? null;
                if ($hasAudioGroup) {
                    $codecs = $this->withAudioCodec($codecs);
                }

                $streams[] = [
                    'bandwidth' => $stream['bandwidth'] ?? 0,
                    'resolution' => $stream['resolution'] ?? '',
                    'name' => $rendition->getAttribute('name', ''),
                    'codecs' => $codecs,
                    'subs' => empty($captions) ? null : 'subs',
                    'audio' => $hasAudioGroup ? 'group_audio' : null,
                    'uri' => $this->withProject(
                        $baseUri . '/renditions/' . $rendition->getId() . '/streams/' . ($stream['id'] ?? 0) . '/playlist.m3u8',
                        $project
                    ),
                ];
            }
        }

        $captionEntries = [];

        // CMAF-DASH fetches /outputs/cmaf/captions/{id}/manifest as text/vtt.
        // The subtitle playlist for CMAF-HLS therefore lives on the hls output.
        $captionOutput = $output === self::OUTPUT_CMAF ? self::OUTPUT_HLS : $output;

        foreach ($captions as $caption) {
            $captionEntries[] = [
                'name' => $caption->getAttribute('name', ''),
                'code' => $caption->getAttribute('code', ''),
                'default' => $caption->getAttribute('default', false) ? 'YES' : 'NO',
                'uri' => $this->withProject(
                    $this->baseUri($video, $captionOutput) . '/captions/' . $caption->getId() . '/manifest',
                    $project
                ),
            ];
        }

        $manifest = $this->renderView('hls-master', [
            'audios' => $audios,
            'captions' => $captionEntries,
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
        $captions = $this->getReadyCaptions($dbForProject, $authorization, $video);

        $baseUri = $this->baseUri($video, $output);

        $mpd = [];
        $entries = [];

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

                $segments = $this->getSegments($dbForProject, $authorization, 'videos_renditions_segments', [
                    Query::equal('renditionInternalId', [$rendition->getSequence()]),
                    Query::equal('streamId', [$streamId]),
                ]);

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
                        $seconds = $this->presentationDurationSeconds($presentation);
                        if ($seconds > 0) {
                            $segAttrs['duration'] = (string) (int) \round(($seconds / \count($media)) * $timescale);
                        }
                    }
                    $representation['segmentList']['attributes'] = $segAttrs;
                }

                // Each Appwrite rendition is packed alone, so stream indexes restart at 0.
                // Representation/@id must still be unique across the merged master.
                $representation['attributes'] = ($representation['attributes'] ?? []);
                $representation['attributes']['id'] = (string) ($representation['attributes']['id'] ?? $streamId)
                    . '-' . $rendition->getId();

                $entries[] = [
                    'attributes' => $adaptation['attributes'] ?? [],
                    'representation' => $representation,
                    // Adaptation id is the source track. Every rendition repeats it
                    // in the same order, so quality rungs of one track share a set
                    // and a second track with the same language does not.
                    'track' => (string) $streamId,
                    // Trailing slash matters: the init/media values above are resolved
                    // against this as a directory.
                    'baseUrl' => $baseUri . '/renditions/' . $rendition->getId() . '/segments/',
                ];
            }
        }

        if (empty($entries)) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $captionEntries = [];

        foreach ($captions as $caption) {
            $captionEntries[] = [
                'id' => $caption->getId(),
                'name' => $caption->getAttribute('code', ''),
                'baseUrl' => $this->withProject($baseUri . '/captions/' . $caption->getId() . '/manifest', $project),
            ];
        }

        $manifest = $this->renderView('dash', [
            'mpd' => $mpd,
            'renditions' => Dash::group($entries),
            'captions' => $captionEntries,
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

        $segments = $this->getSegments($dbForProject, $authorization, 'videos_renditions_segments', [
            Query::equal('renditionInternalId', [$rendition->getSequence()]),
            Query::equal('streamId', [$streamId]),
        ]);

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

    /**
     * Give audio renditions that share a label distinct NAME values.
     *
     * HLS requires NAME, and players present it as the track title. One
     * undetermined track keeps a missing name. A second track with the same
     * label would otherwise be indistinguishable.
     *
     * @param list<array{name: ?string, language: ?string, track: string, uri: string}> $audios
     */
    private function distinctAudioNames(array &$audios): void
    {
        $counts = [];

        foreach ($audios as $audio) {
            $label = \is_string($audio['name']) && $audio['name'] !== '' ? $audio['name'] : '';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        foreach ($audios as $index => $audio) {
            $label = \is_string($audio['name']) && $audio['name'] !== '' ? $audio['name'] : '';

            if (($counts[$label] ?? 0) > 1) {
                $track = $audio['track'] !== '' ? $audio['track'] : (string) $index;
                $audios[$index]['name'] = $label !== '' ? $label . ' ' . $track : 'Audio ' . $track;
            }

            unset($audios[$index]['track']);
        }
    }

    /**
     * Ensure STREAM-INF CODECS lists AAC when an alternate AUDIO group is used.
     *
     * Encodes always use AAC (`mp4a.40.2`). ffmpeg omits it from CODECS when
     * audio is packaged as EXT-X-MEDIA; Safari rejects the variant without it.
     */
    private function withAudioCodec(?string $codecs): string
    {
        $audio = 'mp4a.40.2';

        if ($codecs === null || $codecs === '') {
            return $audio;
        }

        foreach (\explode(',', $codecs) as $part) {
            $part = \trim($part);
            if ($part !== '' && \str_starts_with($part, 'mp4a')) {
                return $codecs;
            }
        }

        return $codecs . ',' . $audio;
    }

    /**
     * Parse an MPD mediaPresentationDuration (`PT1H2M3.5S`) into seconds.
     *
     * Walks the string with digit/unit tokens instead of preg_* (AGENTS.md).
     * Returns 0 when the value is missing or malformed.
     */
    private function presentationDurationSeconds(string $presentation): float
    {
        if (!\str_starts_with($presentation, 'PT') || \strlen($presentation) < 3) {
            return 0.0;
        }

        $rest = \substr($presentation, 2);
        $seconds = 0.0;
        $number = '';
        $length = \strlen($rest);

        for ($i = 0; $i < $length; $i++) {
            $ch = $rest[$i];
            if (($ch >= '0' && $ch <= '9') || $ch === '.') {
                $number .= $ch;
                continue;
            }
            // Same shape as the old ISO-8601 fragment: digits, optional fraction.
            if (
                $number === ''
                || \str_starts_with($number, '.')
                || \str_ends_with($number, '.')
                || \substr_count($number, '.') > 1
            ) {
                return 0.0;
            }

            $value = (float) $number;
            $number = '';

            if ($ch === 'H') {
                $seconds += $value * 3600;
            } elseif ($ch === 'M') {
                $seconds += $value * 60;
            } elseif ($ch === 'S') {
                $seconds += $value;
            } else {
                return 0.0;
            }
        }

        if ($number !== '') {
            return 0.0;
        }

        return $seconds;
    }
}
