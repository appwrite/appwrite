/**
 * One-line explanations for Videos terminology, shown in info tooltips.
 * Values are English source strings; wrap them in `t()` at the render site.
 */
export const VIDEO_GLOSSARY = {
  source:
    'The Storage file this video was created from. Each rendition or timeline job downloads its own copy and deletes it when done.',
  rendition:
    'One encoded copy of the video at a set resolution and bitrate. Players switch between renditions as bandwidth changes.',
  profile:
    'A reusable encoding preset: resolution plus video and audio bitrate. Each rendition is encoded from one profile.',
  output:
    'The streaming format a rendition is packaged in: HLS, DASH, or CMAF.',
  hls: "HTTP Live Streaming. Apple's format: native on Safari and iOS, and works elsewhere with hls.js.",
  dash: 'MPEG-DASH. An open standard supported by most web and TV players, but not native Safari.',
  cmaf: 'Encodes once and serves the same segments as both HLS and DASH. Saves storage and encoding time.',
  manifest:
    'The playlist a player loads first. It lists every rendition and subtitle so the player can pick the best one.',
  mediaPlaylist:
    'A playlist for a single rendition stream. Players reach it from the master manifest.',
  segment:
    'A short chunk of video, a few seconds long. Players download segments one after another.',
  adaptive:
    'Adaptive bitrate: the player picks a quality automatically based on network speed.',
  bitrate:
    'Data per second of video. Higher bitrate means better quality and bigger files.',
  timeline:
    'Thumbnail sprite sheets plus a WebVTT index. Players use it to show previews while scrubbing.',
  sprite: 'A single image that packs many thumbnails into a grid.',
  preview:
    'An image cut from the timeline. The first one is used as the video poster.',
  embedded:
    'Extracted automatically from the source file. Retag its language if it shows as und.',
  defaultTrack: 'Players turn on the default subtitle automatically.',
  languageCode:
    'ISO 639-2 three-letter code, like eng or heb. und means undetermined.',
  targetDuration: 'The longest segment in this rendition, in seconds.',
  upscale:
    'The profile is larger than the source. Upscaling adds size without adding detail.',
  streamIndex:
    'Position of a stream (track) inside the rendition, starting at 0.',
  codec: 'The compression format of a stream, like H.264 for video or AAC for audio.',
  container: 'The file format that wraps the audio and video streams, like MP4.',
} as const

export type VideoGlossaryTerm = keyof typeof VIDEO_GLOSSARY
