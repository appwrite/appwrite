Request a new rendition of a video, encoded against a video profile and packaged for HLS, DASH, or CMAF. The rendition is created immediately with a `pending` status and transcoded in the background; poll it or subscribe to realtime events to follow its progress.

The working copy must already be `ready`. Call the create-source endpoint first and wait until the video status is `ready`. If the working copy has been released (`removed`), this request fails with `video_source_removed`. Until the source is ready, this request fails with `video_not_ready`.

Each video may have only one rendition per profile and output combination. Creating a duplicate fails with `video_rendition_already_exists`. After an encode fails or is aborted, delete the rendition and create it again. The same profile may still be encoded for different outputs (for example HLS and DASH).

CMAF packs shared fMP4 segments once and exposes both HLS (`/outputs/cmaf/master.m3u8`) and DASH (`/outputs/cmaf/master.mpd`) masters for the same encode.
