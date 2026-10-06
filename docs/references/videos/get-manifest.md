Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.

Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the source Storage file is readable to them. A public file (`read("any")`) is playable without a session. A private file is not, even for guests.

For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
