/** Video models, as the Console SDK generates them. */

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
 * Video Codecs List
 */
export type VideoCodecList = {
  /**
   * Total number of codecs that matched your query.
   */
  total: number
  /**
   * List of codecs.
   */
  codecs: VideoCodec[]
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
 * Video Captions List
 */
export type VideoCaptionList = {
  /**
   * Total number of captions that matched your query.
   */
  total: number
  /**
   * List of captions.
   */
  captions: VideoCaption[]
}

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
   * Video permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).
   */
  $permissions: string[]
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
   * Source file size in bytes.
   */
  size: number
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
  /**
   * Thumbnail timeline job status: one of `pending`, `started`, `ready`, `skipped` (source has no video track) or `error`.
   */
  timelineStatus: string
  /**
   * Why the timeline job failed. Empty unless `timelineStatus` is `error`.
   */
  timelineError: string
}

/**
 * Video codec
 */
export type VideoCodec = {
  /**
   * Codec ID.
   */
  $id: string
  /**
   * Codec display name.
   */
  name: string
  /**
   * Streaming output formats this codec may be packaged into.
   */
  outputs: string[]
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
   * Video encode codec: one of `h264`, `hevc`, or `vp9`.
   */
  codec: string
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
   * Transcoding status: one of `pending`, `started`, `ended`, `ready`, `error` or `aborted`.
   */
  status: string
  /**
   * Why the rendition failed or was aborted. Empty unless `status` is `error` or `aborted`.
   */
  error: string
  /**
   * Transcoding progress as a percentage.
   */
  progress: string
  /**
   * Streaming output format: `hls`, `dash`, or `cmaf`.
   */
  output: string
  /**
   * Video encode codec snapshotted from the profile at create time: one of `h264`, `hevc`, or `vp9`.
   */
  codec: string
}

/**
 * Video caption
 */
export type VideoCaption = {
  /**
   * Caption ID.
   */
  $id: string
  /**
   * Caption creation time in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Caption update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * Video ID.
   */
  videoId: string
  /**
   * Storage bucket ID holding the caption file.
   */
  bucketId: string
  /**
   * Caption file ID.
   */
  fileId: string
  /**
   * Caption display name.
   */
  name: string
  /**
   * Caption ISO 639-2 language code.
   */
  code: string
  /**
   * Is this the default caption track?
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
   * Packaging status: one of `pending`, `started`, `ready`, `error` or `aborted`.
   */
  status: string
  /**
   * Why the caption failed or was aborted. Empty unless `status` is `error` or `aborted`.
   */
  error: string
}
