Get the HLS (or CMAF-HLS) media playlist for a single stream of a rendition. Players reach this from the master playlist; it is not usually requested directly.

CMAF stream playlists live under `/outputs/cmaf/renditions/:renditionId/streams/:streamId/playlist.m3u8` and include `#EXT-X-MAP` for the fMP4 initialisation segment.
