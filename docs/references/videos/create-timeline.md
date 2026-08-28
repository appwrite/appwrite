Queue sprite-sheet and WebVTT timeline generation for a video. The working copy must already be `ready`; otherwise the request fails with `video_not_ready` or `video_source_removed`. Audio-only sources have no video track and fail with `video_track_not_found`.

The request is accepted immediately. Poll the get-timeline endpoint until it returns a WebVTT document instead of `video_timeline_not_found`.
