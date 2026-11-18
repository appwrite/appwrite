<?php

namespace Appwrite\Video;

/**
 * Merges per-rendition DASH adaptations into switchable adaptation sets.
 *
 * Each Appwrite rendition is packaged alone, so its metadata carries one
 * AdaptationSet per stream with a single Representation. Players only switch
 * bitrate inside one set, so the master must fold same-track representations
 * together before the MPD is rendered.
 */
final class Dash
{
    /**
     * Group packaged stream entries into one AdaptationSet per switchable track.
     *
     * Video streams share a set. Audio streams share a set per source track and
     * language, so each track stays a player choice and quality rungs of that
     * track stay bitrate alternatives. A language tag is not an identity: two
     * tracks can share one. Within a set, representations are ordered by
     * ascending bandwidth. Each representation keeps its own BaseURL and
     * SegmentList — segment bytes still live under that rendition.
     *
     * @param list<array{
     *     attributes?: array<string, string>,
     *     representation: array<string, mixed>,
     *     baseUrl: string,
     *     track?: string
     * }> $entries One entry per packaged stream (unique representation id already set). `track` is the source audio track.
     * @return list<array{
     *     id: int,
     *     attributes: array<string, string>,
     *     representations: list<array{attributes: array<string, mixed>, segmentList: array<string, mixed>, baseUrl: string}>
     * }>
     */
    public static function group(array $entries): array
    {
        $buckets = [];

        foreach ($entries as $entry) {
            $attributes = $entry['attributes'] ?? [];
            $contentType = (string) ($attributes['contentType'] ?? 'video');
            $track = (string) ($entry['track'] ?? '');
            $key = $contentType === 'audio'
                ? 'audio:' . $track . ':' . (string) ($attributes['lang'] ?? '')
                : $contentType;

            $buckets[$key][] = $entry;
        }

        $sets = [];
        $id = 0;

        foreach ($buckets as $entriesInBucket) {
            \usort(
                $entriesInBucket,
                static function (array $left, array $right): int {
                    $a = (int) ($left['representation']['attributes']['bandwidth'] ?? 0);
                    $b = (int) ($right['representation']['attributes']['bandwidth'] ?? 0);

                    return $a <=> $b;
                }
            );

            $first = $entriesInBucket[0];
            $attributes = $first['attributes'] ?? [];
            $attributes['segmentAlignment'] = 'true';
            $attributes['startWithSAP'] = '1';
            unset($attributes['bitstreamSwitching']);

            $representations = [];

            foreach ($entriesInBucket as $entry) {
                $representation = $entry['representation'];
                $representations[] = [
                    'attributes' => $representation['attributes'] ?? [],
                    'segmentList' => $representation['segmentList'] ?? [],
                    'baseUrl' => $entry['baseUrl'],
                ];
            }

            $sets[] = [
                'id' => $id++,
                'attributes' => $attributes,
                'representations' => $representations,
            ];
        }

        return $sets;
    }

    /**
     * Decimal seconds for a segment row.
     *
     * Nine places round-trip through a media timescale — 48 kHz, 90 kHz, or
     * microseconds — back to the tick the packager wrote.
     */
    public static function duration(float $seconds): string
    {
        $formatted = \rtrim(\sprintf('%.9F', $seconds), '0');

        return \rtrim($formatted, '.');
    }

    /**
     * SegmentTimeline runs for one representation.
     *
     * `SegmentList@duration` is a single length applied to every segment, and
     * the value we have is the longest. A player then seeks to
     * `floor(time / longest)`, so on a long video the index lands on the wrong
     * segment and the tail, whose start falls past the presentation, is never
     * requested. Each `S` carries one segment's own duration in timescale
     * ticks. `r` is how many extra segments repeat `d`. The first run is
     * anchored at `t=0`.
     *
     * Returns nothing when a length is missing or the timescale is unusable,
     * so the caller can keep the single duration for an older encode.
     *
     * @param list<float|int|string|null> $seconds One entry per media segment, in order.
     * @return list<array{t?: int, d: int, r?: int}>
     */
    public static function timeline(array $seconds, int $timescale): array
    {
        if ($timescale <= 0 || $seconds === []) {
            return [];
        }

        $ticks = [];

        foreach ($seconds as $secondsEach) {
            if ($secondsEach === null || $secondsEach === '') {
                return [];
            }

            $tick = (int) \round((float) $secondsEach * $timescale);

            if ($tick <= 0) {
                return [];
            }

            $ticks[] = $tick;
        }

        $runs = [];
        $current = 0;
        $count = 0;

        foreach ($ticks as $tick) {
            if ($count > 0 && $tick === $current) {
                $count++;
                continue;
            }

            if ($count > 0) {
                $runs[] = [$current, $count];
            }

            $current = $tick;
            $count = 1;
        }

        $runs[] = [$current, $count];

        $timeline = [];

        foreach ($runs as $index => [$tick, $repeated]) {
            $entry = ['d' => $tick];

            if ($index === 0) {
                $entry = ['t' => 0, 'd' => $tick];
            }

            if ($repeated > 1) {
                $entry['r'] = $repeated - 1;
            }

            $timeline[] = $entry;
        }

        return $timeline;
    }
}
