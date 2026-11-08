/** Video models, as the Console SDK generates them. */

/**
 * Video
 */
export type Video = {
  /**
   * Video ID.
   */
  $id: string
  /**
   * Video creation time in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Video update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * Storage bucket ID holding the source file.
   */
  bucketId: string
  /**
   * Source file ID.
   */
  fileId: string
  /**
   * Video name.
   */
  name: string
  /**
   * Preview image ID, taken from the sprite timeline.
   */
  previewId: string
  /**
   * Source file size in bytes.
   */
  size: number
  /**
   * Source status: one of `pending`, `downloading`, `ready`, `removed`, `error` or `aborted`.
   */
  status: string
  /**
   * Total number of chunks in the source download.
   */
  chunksTotal: number
  /**
   * Number of source chunks downloaded so far.
   */
  chunksUploaded: number
  /**
   * Container format.
   */
  format: string
  /**
   * Video duration in milliseconds.
   */
  duration: number
  /**
   * Video width in pixels.
   */
  width: number
  /**
   * Video height in pixels.
   */
  height: number
  /**
   * Video aspect ratio.
   */
  aspectRatio: string
  /**
   * Video codec.
   */
  videoCodec: string
  /**
   * Video format.
   */
  videoFormat: string
  /**
   * Video format profile.
   */
  videoFormatProfile: string
  /**
   * Video bitrate in bits per second.
   */
  videoBitRate: number
  /**
   * Video frame rate.
   */
  videoFrameRate: string
  /**
   * Video frame rate mode.
   */
  videoFrameRateMode: string
  /**
   * Audio codec.
   */
  audioCodec: string
  /**
   * Audio format.
   */
  audioFormat: string
  /**
   * Audio bitrate in bits per second.
   */
  audioBitRate: number
  /**
   * Audio sample rate.
   */
  audioSampleRate: string
}

/**
 * Videos List
 */
export type VideoList = {
  /**
   * Total number of videos that matched your query.
   */
  total: number
  /**
   * List of videos.
   */
  videos: Video[]
}

/**
 * Video profile
 */
export type VideoProfile = {
  /**
   * Video profile ID.
   */
  $id: string
  /**
   * Video profile creation time in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Video profile update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * Video profile name.
   */
  name: string
  /**
   * Target video bitrate in kilobits per second.
   */
  videoBitRate: number
  /**
   * Target audio bitrate in kilobits per second.
   */
  audioBitRate: number
  /**
   * Target video width in pixels.
   */
  width: number
  /**
   * Target video height in pixels.
   */
  height: number
}

/**
 * Video Profiles List
 */
export type VideoProfileList = {
  /**
   * Total number of profiles that matched your query.
   */
  total: number
  /**
   * List of profiles.
   */
  profiles: VideoProfile[]
}

/**
 * Video rendition
 */
export type VideoRendition = {
  /**
   * Rendition ID.
   */
  $id: string
  /**
   * Rendition creation time in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Rendition update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * Video ID.
   */
  videoId: string
  /**
   * Video profile ID this rendition was encoded against.
   */
  profileId: string
  /**
   * Rendition name, derived from its dimensions and bitrate.
   */
  name: string
  /**
   * Transcoding start time in ISO 8601 format.
   */
  startedAt: string
  /**
   * Transcoding end time in ISO 8601 format.
   */
  endedAt: string
  /**
   * Rendition width in pixels.
   */
  width: number
  /**
   * Rendition height in pixels.
   */
  height: number
  /**
   * Video bitrate in kilobits per second.
   */
  videoBitRate: number
  /**
   * Audio bitrate in kilobits per second.
   */
  audioBitRate: number
  /**
   * Longest segment duration in seconds.
   */
  targetDuration: string
  /**
   * Transcoding status: one of `pending`, `started`, `ended`, `uploading`, `ready`, `error` or `aborted`.
   */
  status: string
  /**
   * Transcoding progress as a percentage.
   */
  progress: string
  /**
   * Streaming output format: `hls`, `dash`, or `cmaf`.
   */
  output: string
}

/**
 * Video Renditions List
 */
export type VideoRenditionList = {
  /**
   * Total number of renditions that matched your query.
   */
  total: number
  /**
   * List of renditions.
   */
  renditions: VideoRendition[]
}

/**
 * Video subtitle
 */
export type VideoSubtitle = {
  /**
   * Subtitle ID.
   */
  $id: string
  /**
   * Subtitle creation time in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Subtitle update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * Video ID.
   */
  videoId: string
  /**
   * Storage bucket ID holding the subtitle file.
   */
  bucketId: string
  /**
   * Subtitle file ID.
   */
  fileId: string
  /**
   * Subtitle display name.
   */
  name: string
  /**
   * Subtitle ISO 639-2 language code.
   */
  code: string
  /**
   * Is this the default subtitle track?
   */
  default: boolean
  /**
   * Was this track auto-extracted from the source container? Extracted tracks have no backing file; extraction runs once per video, so a deleted extracted track is not re-created.
   */
  embedded: boolean
  /**
   * Longest segment duration in seconds.
   */
  targetDuration: string
  /**
   * Packaging status: one of `pending`, `started`, `ready` or `error`.
   */
  status: string
}

/**
 * Video Subtitles List
 */
export type VideoSubtitleList = {
  /**
   * Total number of subtitles that matched your query.
   */
  total: number
  /**
   * List of subtitles.
   */
  subtitles: VideoSubtitle[]
}
