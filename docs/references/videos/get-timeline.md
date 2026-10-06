Get the WebVTT sprite timeline for a video, used to render scrubbing thumbnails in a player. Timeline generation is queued when the video is created. Subscribe to `videos.[videoId].timeline.update` for probe and terminal updates, or poll this endpoint until it returns a WebVTT document. Audio-only sources produce no timeline and this endpoint returns `video_timeline_not_found`.

Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the source Storage file is readable to them. A public file (`read("any")`) is playable without a session.

Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the source Storage file is readable to them. A public file (`read("any")`) is playable without a session.
