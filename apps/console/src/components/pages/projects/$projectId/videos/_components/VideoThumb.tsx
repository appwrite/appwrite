import type { Models } from '@appwrite.io/console'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import { cn } from '@/lib/utils'
import { formatVideoDuration } from '@/lib/utils/video-format'

/** Neutral placeholder until posters can be set from a timeline frame. */
export function VideoThumb({
  video,
  className,
  showDuration = false,
}: {
  projectId: string
  video: Pick<Models.Video, '$id' | 'name' | 'duration'>
  width?: number
  className?: string
  showDuration?: boolean
}) {
  return (
    <div
      className={cn(
        'relative flex items-center justify-center overflow-hidden bg-muted text-muted-foreground',
        className,
      )}
    >
      <VIDEOS_PRODUCT_ICON className="h-5 w-5" />
      {showDuration && video.duration > 0 ? (
        <span className="absolute bottom-1.5 end-1.5 rounded bg-black/70 px-1.5 py-0.5 font-mono text-[10px] text-white">
          {formatVideoDuration(video.duration)}
        </span>
      ) : null}
    </div>
  )
}
