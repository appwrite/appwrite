import { VideoOutput } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export type VideoMasterManifestKind = 'hls' | 'dash' | 'cmaf-hls' | 'cmaf-dash'

export const VIDEO_MASTER_MANIFESTS: Array<{
  kind: VideoMasterManifestKind
  output: VideoOutput
  label: string
  file: string
}> = [
  { kind: 'hls', output: VideoOutput.Hls, label: 'HLS', file: 'master.m3u8' },
  { kind: 'dash', output: VideoOutput.Dash, label: 'DASH', file: 'master.mpd' },
  {
    kind: 'cmaf-hls',
    output: VideoOutput.Cmaf,
    label: 'CMAF (HLS)',
    file: 'master.m3u8',
  },
  {
    kind: 'cmaf-dash',
    output: VideoOutput.Cmaf,
    label: 'CMAF (DASH)',
    file: 'master.mpd',
  },
]

/** Public master manifest URL, as a client app would request it. */
export function getVideoMasterManifestUrl(
  projectId: string,
  videoId: string,
  kind: VideoMasterManifestKind,
): string {
  const videos = sdk.forProject(projectId).videos
  switch (kind) {
    case 'hls':
      return String(videos.getHlsManifest({ videoId }))
    case 'dash':
      return String(videos.getDashManifest({ videoId }))
    case 'cmaf-hls':
      return String(videos.getCmafHlsManifest({ videoId }))
    case 'cmaf-dash':
      return String(videos.getCmafDashManifest({ videoId }))
  }
}

/** Per-rendition media playlist. DASH has no per-stream playlist, so it returns null. */
export function getVideoRenditionPlaylistUrl(
  projectId: string,
  videoId: string,
  renditionId: string,
  output: string,
  streamId = 0,
): string | null {
  const videos = sdk.forProject(projectId).videos
  if (output === 'hls') {
    return String(videos.getStreamManifest({ videoId, renditionId, streamId }))
  }
  if (output === 'cmaf') {
    return String(
      videos.getCmafStreamManifest({ videoId, renditionId, streamId }),
    )
  }
  return null
}

export function getVideoSubtitleManifestUrl(
  projectId: string,
  videoId: string,
  subtitleId: string,
  output: string,
): string {
  return String(
    sdk.forProject(projectId).videos.getSubtitleManifest({
      videoId,
      subtitleId,
      output: output as VideoOutput,
    }),
  )
}
