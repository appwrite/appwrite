The Videos service allows you to transcode video files stored in Appwrite Storage into adaptive streaming formats. Upload a video to a bucket, create a video resource from it, then request renditions at the quality levels you need — Appwrite packages them as HLS, DASH, or CMAF and serves the manifests and segments for playback. You can also attach subtitle tracks and generate sprite timelines for player scrubbing.

On create (and when the source file is updated), Appwrite probes the source and automatically registers **text-based** embedded subtitle streams (for example SubRip, ASS, or `mov_text`) as ready subtitle tracks. Image-based streams such as PGS or VobSub are skipped. Uploaded WebVTT or SubRip files always win for the same language code: creating or updating an uploaded track replaces any auto-extracted track with that code.

Playback masters:

- HLS: `/v1/videos/:videoId/outputs/hls/master.m3u8`
- DASH: `/v1/videos/:videoId/outputs/dash/master.mpd`
- CMAF HLS: `/v1/videos/:videoId/outputs/cmaf/master.m3u8`
- CMAF DASH: `/v1/videos/:videoId/outputs/cmaf/master.mpd`
