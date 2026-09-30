<?php

/**
 * Video encode codecs available to profiles and renditions.
 *
 * `enabled` controls whether GET /videos/codecs lists the codec (and whether
 * GET /videos/profiles?codec=… may browse its ladder). Create still accepts
 * every key so e2e can encode disabled codecs before they ship in the console.
 *
 * `outputs` is the packaging a codec may be asked for on POST /renditions.
 * VP9 is DASH-only (utopia-php/video Format\VP9).
 */
return [
    'h264' => [
        'name' => 'H.264',
        'enabled' => true,
        'outputs' => ['hls', 'dash', 'cmaf'],
    ],
    'hevc' => [
        'name' => 'HEVC',
        'enabled' => false,
        'outputs' => ['hls', 'dash', 'cmaf'],
    ],
    'vp9' => [
        'name' => 'VP9',
        'enabled' => false,
        'outputs' => ['dash'],
    ],
];
