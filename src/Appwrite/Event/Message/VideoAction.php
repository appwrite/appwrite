<?php

namespace Appwrite\Event\Message;

/**
 * The unit of work a videos job represents. Each value corresponds to one
 * ffmpeg-backed artifact derived from a source video file.
 */
enum VideoAction: string
{
    /** Extract sprite sheets and build the WebVTT scrubbing timeline. */
    case Timeline = 'timeline';

    /** Normalise an uploaded subtitle file to WebVTT and segment it. */
    case Subtitle = 'subtitle';

    /** Transcode into an HLS or DASH rendition against a profile. */
    case Encode = 'encode';
}
