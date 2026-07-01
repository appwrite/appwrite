import { useEffect, useRef, useState, type ReactNode } from 'react'
import {
  INIT_TICKET_MAX_WIDTH_PX,
  initTicketDisplayAspectRatio,
} from '@/lib/init/ticket-layout'
import { cn } from '@/lib/utils'

export function getInitTicketScaledDimensions(widthPx: number) {
  const scale = widthPx / INIT_TICKET_MAX_WIDTH_PX
  const heightPx = widthPx / initTicketDisplayAspectRatio()
  return { widthPx, heightPx, scale }
}

type InitTicketScaledFrameProps = {
  children: ReactNode
  className?: string
  /** Fixed display width. Omit with `measureContainer` to fit the parent. */
  widthPx?: number
  /** Sizes to the parent width (capped at max ticket width). */
  measureContainer?: boolean
  pointerEventsNone?: boolean
  /** Allow tilt/video capture to extend outside the layout box without clipping. */
  overflowVisible?: boolean
}

function ScaledTicketShell({
  widthPx,
  pointerEventsNone,
  overflowVisible,
  className,
  children,
}: {
  widthPx: number
  pointerEventsNone?: boolean
  overflowVisible?: boolean
  className?: string
  children: ReactNode
}) {
  const { heightPx, scale } = getInitTicketScaledDimensions(widthPx)

  return (
    <div
      className={cn(
        'relative shrink-0 touch-none',
        overflowVisible ? 'overflow-visible' : 'overflow-hidden',
        className,
      )}
      style={{ width: widthPx, height: heightPx, touchAction: 'none' }}
    >
      <div
        className={cn(
          'absolute start-0 top-0 origin-top-start will-change-transform',
          pointerEventsNone && 'pointer-events-none',
        )}
        style={{
          width: INIT_TICKET_MAX_WIDTH_PX,
          transform: `scale(${scale})`,
        }}
      >
        {children}
      </div>
    </div>
  )
}

export function InitTicketScaledFrame({
  children,
  className,
  widthPx: fixedWidthPx,
  measureContainer = false,
  pointerEventsNone = false,
  overflowVisible = false,
}: InitTicketScaledFrameProps) {
  const measureRef = useRef<HTMLDivElement>(null)
  const [measuredWidthPx, setMeasuredWidthPx] = useState(
    fixedWidthPx ?? INIT_TICKET_MAX_WIDTH_PX,
  )

  useEffect(() => {
    if (!measureContainer || fixedWidthPx !== undefined) return

    const el = measureRef.current
    if (!el) return

    const update = () => {
      const width = el.getBoundingClientRect().width
      if (width > 0) {
        setMeasuredWidthPx(Math.min(INIT_TICKET_MAX_WIDTH_PX, width))
      }
    }

    update()
    const observer = new ResizeObserver(update)
    observer.observe(el)
    return () => observer.disconnect()
  }, [fixedWidthPx, measureContainer])

  useEffect(() => {
    if (fixedWidthPx !== undefined) {
      setMeasuredWidthPx(fixedWidthPx)
    }
  }, [fixedWidthPx])

  const widthPx = fixedWidthPx ?? measuredWidthPx

  if (measureContainer) {
    return (
      <div ref={measureRef} className={cn('w-full max-w-[820px]', className)}>
        <ScaledTicketShell
          widthPx={widthPx}
          pointerEventsNone={pointerEventsNone}
          overflowVisible={overflowVisible}
          className="mx-auto"
        >
          {children}
        </ScaledTicketShell>
      </div>
    )
  }

  return (
    <ScaledTicketShell
      widthPx={widthPx}
      pointerEventsNone={pointerEventsNone}
      overflowVisible={overflowVisible}
      className={className}
    >
      {children}
    </ScaledTicketShell>
  )
}
