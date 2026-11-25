import type { Models } from '@appwrite.io/console'

/**
 * Playback for a visitor who may not read the video document.
 *
 * Guests have the `videos.play` scope but not `videos.read`, so the embed
 * player cannot load the video or list its renditions for them. The HLS master
 * playlist is a playback route, authorized by the video's own read
 * permission, and it names every ready HLS rendition with its resolution:
 * enough for the player to start a public video.
 */
export interface PublicHlsPlayback {
  video: Models.Video
  renditions: Models.VideoRendition[]
}

interface MasterRendition {
  $id: string
  width: number
  height: number
}

const STREAM_INF = '#EXT-X-STREAM-INF:'
const RESOLUTION = 'RESOLUTION='
const RENDITIONS_SEGMENT = '/renditions/'

function parseResolution(attributes: string): {
  width: number
  height: number
} {
  const start = attributes.indexOf(RESOLUTION)
  if (start < 0) return { width: 0, height: 0 }
  const value = attributes.slice(start + RESOLUTION.length).split(',')[0] ?? ''
  const [width, height] = value
    .split('x')
    .map((part) => Number.parseInt(part, 10))
  return {
    width: Number.isFinite(width) ? width : 0,
    height: Number.isFinite(height) ? height : 0,
  }
}

function renditionIdFromUri(uri: string): string | null {
  const start = uri.indexOf(RENDITIONS_SEGMENT)
  if (start < 0) return null
  const id = uri.slice(start + RENDITIONS_SEGMENT.length).split('/')[0]
  return id || null
}

/** The ready HLS renditions a master playlist lists, largest picture first. */
export function parseHlsMasterRenditions(manifest: string): MasterRendition[] {
  const lines = manifest.split('\n').map((line) => line.trim())
  const byId = new Map<string, MasterRendition>()

  lines.forEach((line, index) => {
    if (!line.startsWith(STREAM_INF)) return
    const uri = lines
      .slice(index + 1)
      .find((next) => next !== '' && !next.startsWith('#'))
    const id = uri ? renditionIdFromUri(uri) : null
    if (!id) return
    const { width, height } = parseResolution(line.slice(STREAM_INF.length))
    const known = byId.get(id)
    if (!known || width * height > known.width * known.height) {
      byId.set(id, { $id: id, width, height })
    }
  })

  return [...byId.values()].sort(
    (a, b) => b.width * b.height - a.width * a.height,
  )
}

/** Video and renditions the player can start from, or null when none is ready. */
export function publicHlsPlayback(
  videoId: string,
  manifest: string,
): PublicHlsPlayback | null {
  const ready = parseHlsMasterRenditions(manifest)
  if (ready.length === 0) return null
  const largest = ready[0]

  const video: Models.Video = {
    $id: videoId,
    $createdAt: '',
    $updatedAt: '',
    $permissions: [],
    bucketId: '',
    fileId: '',
    name: '',
    size: 0,
    format: '',
    duration: 0,
    width: largest.width,
    height: largest.height,
    aspectRatio: '',
    videoCodec: '',
    videoFormat: '',
    videoFormatProfile: '',
    videoBitRate: 0,
    videoFrameRate: '',
    videoFrameRateMode: '',
    audioCodec: '',
    audioFormat: '',
    audioBitRate: 0,
    audioSampleRate: '',
    timelineStatus: '',
    timelineError: '',
  }

  const renditions: Models.VideoRendition[] = ready.map((rendition) => ({
    $id: rendition.$id,
    $createdAt: '',
    $updatedAt: '',
    videoId,
    profileId: '',
    name: '',
    startedAt: '',
    endedAt: '',
    width: rendition.width,
    height: rendition.height,
    videoBitRate: 0,
    audioBitRate: 0,
    targetDuration: '',
    status: 'ready',
    error: '',
    progress: '100',
    output: 'hls',
    codec: '',
  }))

  return { video, renditions }
}
