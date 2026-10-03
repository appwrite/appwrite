import type { Models } from '@appwrite.io/console'
import { getVideoEncodingStatus } from '@/lib/react-query/hooks/videos'
import { VIDEO_TAB_NEEDS_READY_RENDITION } from '@/components/pages/projects/$projectId/videos/_components/video-tabs'

/** English reason preview/embed are unavailable, or null when they should work. */
export function getVideoEmbedPlaybackDisabledReason(
  video: Pick<Models.Video, 'duration'>,
  renditions: Pick<Models.VideoRendition, 'status'>[],
): string | null {
  const hasReady = renditions.some((r) => r.status === 'ready')
  if (hasReady) return null
  if ((video.duration ?? 0) > 0) return null
  const status = getVideoEncodingStatus(renditions)
  if (status === 'encoding') {
    return 'The source file is still being probed. Try again when encoding starts or finishes.'
  }
  return VIDEO_TAB_NEEDS_READY_RENDITION
}
