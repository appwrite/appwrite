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
}
