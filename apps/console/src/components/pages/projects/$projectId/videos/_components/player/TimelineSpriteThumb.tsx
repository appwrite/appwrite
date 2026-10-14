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
  fillParent = false,
  fit = 'cover',
}: {
  cue: VideoTimelineCue
  width?: number
  className?: string
  /** Size to the parent box (parent should set aspect ratio). */
  fillParent?: boolean
  /** How the frame fills the parent when `fillParent` is set. */
  fit?: 'cover' | 'contain'
}) {
  const containerRef = useRef<HTMLDivElement | null>(null)
  const [measuredSize, setMeasuredSize] = useState({ width: 0, height: 0 })
  const fluid = width === undefined

  useEffect(() => {
    if (!fluid && !fillParent) return
    const el = containerRef.current
    if (!el) return
    const update = () => {
      const rect = el.getBoundingClientRect()
      setMeasuredSize({ width: rect.width, height: rect.height })
    }
    update()
    const observer = new ResizeObserver(update)
    observer.observe(el)
    return () => observer.disconnect()
  }, [fluid, fillParent])

  const hasSize = cue.width > 0 && cue.height > 0
  const { width: measuredWidth, height: measuredHeight } = measuredSize
  const renderWidth = fluid || fillParent ? measuredWidth : width
  const scale =
    hasSize && fillParent && fit === 'contain' && measuredWidth > 0 && measuredHeight > 0
      ? Math.min(measuredWidth / cue.width, measuredHeight / cue.height)
      : hasSize && renderWidth > 0
        ? renderWidth / cue.width
        : 0
  const scaledWidth = cue.width * scale
  const scaledHeight = cue.height * scale
  const offsetX =
    fillParent && fit === 'contain' && measuredWidth > 0
      ? (measuredWidth - scaledWidth) / 2
      : 0
  const offsetY =
    fillParent && fit === 'contain' && measuredHeight > 0
      ? (measuredHeight - scaledHeight) / 2
      : 0

  return (
    <div
      ref={containerRef}
      className={cn(
        'relative shrink-0 overflow-hidden bg-muted',
        fluid && !fillParent && 'w-full',
        fillParent && 'h-full w-full',
        className,
      )}
      style={
        fillParent
          ? undefined
          : {
              width: fluid ? undefined : width,
              aspectRatio: hasSize ? `${cue.width} / ${cue.height}` : '16 / 9',
            }
      }
    >
      {scale > 0 ? (
        <div
          aria-hidden
          className="absolute origin-top-left"
          style={{
            left: offsetX,
            top: offsetY,
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
