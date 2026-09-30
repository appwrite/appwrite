import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { Film } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { cn } from '@/lib/utils'
import { formatVideoDuration } from '@/lib/utils/video-format'

/** Poster from the sprite timeline (`previewId`), or a neutral placeholder. */
export function VideoThumb({
  projectId,
  video,
  width = 480,
  className,
  showDuration = false,
}: {
  projectId: string
  video: Pick<Models.Video, '$id' | 'previewId' | 'name' | 'duration'>
  width?: number
  className?: string
  showDuration?: boolean
}) {
  const [failed, setFailed] = useState(false)
  const src =
    video.previewId && !failed
      ? withAdminMode(
          sdk.forProject(projectId).videos.getPreview({
            videoId: video.$id,
            previewId: video.previewId,
            width,
          }),
        )
      : null

  return (
    <div
      className={cn(
        'relative flex items-center justify-center overflow-hidden bg-muted text-muted-foreground',
        className,
      )}
    >
      {src ? (
        <img
          src={src}
          alt=""
          loading="lazy"
          className="h-full w-full object-cover"
          onError={() => setFailed(true)}
        />
      ) : (
        <Film className="h-5 w-5" />
      )}
      {showDuration && video.duration > 0 ? (
        <span className="absolute bottom-1.5 end-1.5 rounded bg-black/70 px-1.5 py-0.5 font-mono text-[10px] text-white">
          {formatVideoDuration(video.duration)}
        </span>
      ) : null}
    </div>
  )
}
