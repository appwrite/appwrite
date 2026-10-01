Request a new rendition of a video, encoded against a video profile and packaged for HLS, DASH, or CMAF. The rendition is created immediately with a `pending` status and transcoded in the background; poll it or subscribe to realtime events to follow its progress.

The profile codec must be enabled on this instance. A disabled codec fails with `video_codec_disabled`.

A rendition can be created while the source is `pending`, `downloading`, or `ready`. While the working copy is still downloading, the rendition stays `pending` and encoding starts once the copy is ready. `error` and `aborted` fail with `video_not_ready`. If the working copy has been released (`removed`), or `ready` is set but the file is gone, this request fails with `video_source_removed`. Call the create-source endpoint and wait until the video status is `ready` before retrying.

Each video may have only one rendition per profile and output combination. Creating a duplicate fails with `video_rendition_already_exists`. After an encode fails or is aborted, delete the rendition and create it again. The same profile may still be encoded for different outputs (for example HLS and DASH).

CMAF packs shared fMP4 segments once and exposes both HLS (`/outputs/cmaf/master.m3u8`) and DASH (`/outputs/cmaf/master.mpd`) masters for the same encode.
