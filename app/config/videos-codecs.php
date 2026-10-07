<?php

/**
 * Video encode codecs available to profiles and renditions.
 *
 * `enabled` controls whether a codec may be listed, used to create a profile,
 * or encoded into a rendition. Disabled keys stay in this file so their
 * packaging rules remain defined until they ship.
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
