import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import {
  getVideoPosterCueIndex,
  sampleTimelineCuesForPreview,
} from '@/lib/videos/timeline-cues'
import {
  videoTimelineQueryOptions,
  type VideoTimelineCue,
} from '@/lib/react-query/hooks/videos'
import { cn } from '@/lib/utils'
import {
  formatVideoDuration,
  videoThumbAspectRatio,
} from '@/lib/utils/video-format'
import { TimelineSpriteThumb } from './player/TimelineSpriteThumb'

const HOVER_FRAME_INTERVAL_MS = 180

/**
 * Poster from the sprite timeline when available, else `previewId`, else a placeholder.
 * Hover cycles timeline frames when a timeline exists.
 */
export function VideoThumb({
  projectId,
  video,
  width = 480,
  className,
  showDuration = false,
  timelineCues: timelineCuesProp,
  fetchTimeline = true,
  uniformSize = false,
}: {
  projectId: string
  video: Pick<
    Models.Video,
    '$id' | 'previewId' | 'name' | 'duration' | 'width' | 'height' | 'aspectRatio'
  >
  width?: number
  className?: string
  showDuration?: boolean
  /** When set, skips fetching timeline data for this thumb. */
  timelineCues?: VideoTimelineCue[] | null
  fetchTimeline?: boolean
  /** Fixed 16:9 box; media is letterboxed to its aspect ratio (videos list). */
  uniformSize?: boolean
}) {
  const [previewFailed, setPreviewFailed] = useState(false)
  const [hovering, setHovering] = useState(false)
  const [hoverFrameIndex, setHoverFrameIndex] = useState(0)

  const shouldFetchTimeline =
    fetchTimeline && timelineCuesProp === undefined && !!projectId && !!video.$id

  const timelineQuery = useQuery({
    ...videoTimelineQueryOptions(projectId, video.$id),
    enabled: shouldFetchTimeline,
  })

  const cues = timelineCuesProp ?? timelineQuery.data?.cues ?? null
  const hasTimelineCues = (cues?.length ?? 0) > 0

  const posterCueIndex = useMemo(
    () => (cues ? getVideoPosterCueIndex(cues, video.previewId) : 0),
    [cues, video.previewId],
  )

  const hoverFrames = useMemo(
    () => (cues ? sampleTimelineCuesForPreview(cues) : []),
    [cues],
  )

  const previewSrc =
    !hasTimelineCues && video.previewId && !previewFailed
      ? withAdminMode(
          sdk.forProject(projectId).videos.getPreview({
            videoId: video.$id,
            previewId: video.previewId,
            width,
          }),
        )
      : null

  useEffect(() => {
    if (!hovering) {
      setHoverFrameIndex(0)
      return
    }
    if (hoverFrames.length <= 1) return

    const reducedMotion = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
    if (reducedMotion) return

    const id = window.setInterval(() => {
      setHoverFrameIndex((index) => (index + 1) % hoverFrames.length)
    }, HOVER_FRAME_INTERVAL_MS)
    return () => window.clearInterval(id)
  }, [hovering, hoverFrames.length])

  const posterCue = hasTimelineCues ? cues![posterCueIndex] : null
  const activeCue =
    hasTimelineCues && hovering && hoverFrames.length > 1
      ? hoverFrames[hoverFrameIndex]
      : posterCue

  const aspectRatio = useMemo(
    () => videoThumbAspectRatio(video, posterCue),
    [video, posterCue],
  )

  return (
    <div
      className={cn(
        'relative flex items-center justify-center overflow-hidden bg-muted text-muted-foreground',
        uniformSize && 'aspect-video',
        className,
      )}
      style={uniformSize ? undefined : { aspectRatio }}
      onMouseEnter={() => setHovering(true)}
      onMouseLeave={() => setHovering(false)}
    >
      {activeCue ? (
        <TimelineSpriteThumb
          cue={activeCue}
          fillParent
          fit={uniformSize ? 'contain' : 'cover'}
          className="absolute inset-0"
        />
      ) : previewSrc ? (
        <img
          src={previewSrc}
          alt=""
          loading="lazy"
          className={cn(
            'h-full w-full',
            uniformSize ? 'object-contain' : 'object-cover',
          )}
          onError={() => setPreviewFailed(true)}
        />
      ) : (
        <VIDEOS_PRODUCT_ICON className="h-5 w-5" />
      )}
      {showDuration && video.duration > 0 ? (
        <span className="absolute bottom-1.5 end-1.5 rounded bg-black/70 px-1.5 py-0.5 font-mono text-[10px] text-white">
          {formatVideoDuration(video.duration)}
        </span>
      ) : null}
    </div>
  )
}
