Request a new rendition of a video, encoded against a video profile and packaged for HLS, DASH, or CMAF. The rendition is created immediately with a `waiting` status and transcoded in the background; poll it or subscribe to realtime events to follow its progress.

The working copy must already be `ready`. Call the create-source endpoint first and wait until the video status is `ready`. If the working copy has been released (`removed`), this request fails with `video_source_removed`. Until the source is ready, this request fails with `video_not_ready`.

CMAF packs shared fMP4 segments once and exposes both HLS (`/outputs/cmaf/master.m3u8`) and DASH (`/outputs/cmaf/master.mpd`) masters for the same encode.
