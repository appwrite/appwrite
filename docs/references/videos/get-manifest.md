Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.

For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
