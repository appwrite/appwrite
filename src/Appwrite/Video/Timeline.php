<?php

namespace Appwrite\Video;

/**
 * WebVTT scrubbing timeline helpers.
 */
final class Timeline
{
    /**
     * Rewrites `previews/{id}`, `sprites/{id}`, and `thumbnails/{id}` cue URIs
     * to `thumbnails/{id}?project=...` before any `#xywh=` fragment.
     *
     * @param array<string, string> $binding query parameters every cue URL carries
     */
    public static function withProjectOnThumbnailCues(string $vtt, array $binding): string
    {
        $lines = \explode("\n", $vtt);
        $query = \http_build_query($binding, '', '&');

        foreach ($lines as $index => $line) {
            $trimmed = \rtrim($line, "\r");

            if (\str_starts_with($trimmed, 'previews/')) {
                $trimmed = 'thumbnails/' . \substr($trimmed, \strlen('previews/'));
            } elseif (\str_starts_with($trimmed, 'sprites/')) {
                $trimmed = 'thumbnails/' . \substr($trimmed, \strlen('sprites/'));
            } elseif (!\str_starts_with($trimmed, 'thumbnails/')) {
                continue;
            }

            $fragment = '';
            $hash = \strpos($trimmed, '#');
            if ($hash !== false) {
                $fragment = \substr($trimmed, $hash);
                $trimmed = \substr($trimmed, 0, $hash);
            }

            $lines[$index] = $trimmed . (\str_contains($trimmed, '?') ? '&' : '?') . $query . $fragment;
        }

        return \implode("\n", $lines);
    }
}
