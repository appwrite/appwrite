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
     */
    public static function withProjectOnThumbnailCues(string $vtt, string $projectId): string
    {
        $lines = \explode("\n", $vtt);

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

            $query = (\str_contains($trimmed, '?') ? '&' : '?') . 'project=' . \urlencode($projectId);
            $lines[$index] = $trimmed . $query . $fragment;
        }

        return \implode("\n", $lines);
    }
}
