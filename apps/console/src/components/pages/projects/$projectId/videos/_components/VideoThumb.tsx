import type { Models } from '@appwrite.io/console'
import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import {
  canFetchVideoTimeline,
  videoTimelineQueryOptions,
  type VideoTimelineCue,
} from '@/lib/react-query/hooks/videos'
import { sampleTimelineCuesForPreview } from '@/lib/videos/timeline-cues'
import { cn } from '@/lib/utils'
import {
  formatVideoDuration,
  videoThumbAspectRatio,
} from '@/lib/utils/video-format'
import { TimelineSpriteThumb } from './player/TimelineSpriteThumb'
import { usePosterCue } from './use-poster-cue'

const HOVER_FRAME_INTERVAL_MS = 180

/**
 * Poster from the best timeline frame when a timeline exists (see
 * `usePosterCue`), else a placeholder. Hover cycles timeline frames.
 */
export function VideoThumb({
  projectId,
  video,
  className,
  showDuration = false,
  timelineCues: timelineCuesProp,
  fetchTimeline = true,
  uniformSize = false,
}: {
  projectId: string
  video: Pick<
    Models.Video,
    | '$id'
    | 'timelineStatus'
    | 'name'
    | 'duration'
    | 'width'
    | 'height'
    | 'aspectRatio'
  >
  className?: string
  showDuration?: boolean
  /** When set, skips fetching timeline data for this thumb. */
  timelineCues?: VideoTimelineCue[] | null
  fetchTimeline?: boolean
  /** Fixed 16:9 box; media is letterboxed to its aspect ratio (videos list). */
  uniformSize?: boolean
}) {
  const [hovering, setHovering] = useState(false)
  const [hoverFrameIndex, setHoverFrameIndex] = useState(0)

  const shouldFetchTimeline =
    fetchTimeline &&
    timelineCuesProp === undefined &&
    !!projectId &&
    !!video.$id &&
    canFetchVideoTimeline(video)

  const timelineQuery = useQuery({
    ...videoTimelineQueryOptions(projectId, video.$id),
    enabled: shouldFetchTimeline,
  })

  const cues = timelineCuesProp ?? timelineQuery.data?.cues ?? null
  const hasTimelineCues = (cues?.length ?? 0) > 0

  const hoverFrames = useMemo(
    () => (cues ? sampleTimelineCuesForPreview(cues) : []),
    [cues],
  )

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

  const posterCue = usePosterCue(hasTimelineCues ? cues : null)
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
        'relative flex items-center justify-center overflow-hidden text-muted-foreground',
        // Letterbox bars read as part of the picture, like a player.
        activeCue ? 'bg-black' : 'bg-muted',
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
