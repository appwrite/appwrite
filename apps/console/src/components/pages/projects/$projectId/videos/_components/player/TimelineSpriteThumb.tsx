import { useEffect, useRef, useState } from 'react'
import type { VideoTimelineCue } from '@/lib/react-query/hooks/videos'
import { cn } from '@/lib/utils'

/**
 * Crops one frame from a timeline sprite sheet (WebVTT `#xywh` cue).
 * Pass `width` for a fixed size, or omit it to fill the container width.
 */
export function TimelineSpriteThumb({
  cue,
  width,
  className,
}: {
  cue: VideoTimelineCue
  width?: number
  className?: string
}) {
  const containerRef = useRef<HTMLDivElement | null>(null)
  const [measuredWidth, setMeasuredWidth] = useState(0)
  const fluid = width === undefined

  useEffect(() => {
    if (!fluid) return
    const el = containerRef.current
    if (!el) return
    const update = () => setMeasuredWidth(el.getBoundingClientRect().width)
    update()
    const observer = new ResizeObserver(update)
    observer.observe(el)
    return () => observer.disconnect()
  }, [fluid])

  const hasSize = cue.width > 0 && cue.height > 0
  const renderWidth = fluid ? measuredWidth : width
  const scale = hasSize && renderWidth > 0 ? renderWidth / cue.width : 0

  return (
    <div
      ref={containerRef}
      className={cn(
        'relative shrink-0 overflow-hidden bg-muted',
        fluid && 'w-full',
        className,
      )}
      style={{
        width: fluid ? undefined : width,
        aspectRatio: hasSize ? `${cue.width} / ${cue.height}` : '16 / 9',
      }}
    >
      {scale > 0 ? (
        <div
          aria-hidden
          className="absolute left-0 top-0 origin-top-left"
          style={{
            width: cue.width,
            height: cue.height,
            backgroundImage: `url("${cue.imageUrl}")`,
            backgroundRepeat: 'no-repeat',
            backgroundPosition: `-${cue.x}px -${cue.y}px`,
            transform: `scale(${scale})`,
          }}
        />
      ) : null}
    </div>
  )
}
