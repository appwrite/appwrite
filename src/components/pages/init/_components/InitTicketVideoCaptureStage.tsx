import { forwardRef, useImperativeHandle, useRef, type ReactNode } from 'react'
import { INIT_TICKET_MAX_WIDTH_PX } from '@/lib/init/ticket-layout'
import { cn } from '@/lib/utils'

type InitTicketVideoCaptureStageProps = {
  children: ReactNode
  className?: string
}

/**
 * 16:9 stage for the Init ticket section. Export draws frames off-screen;
 * this wrapper only sizes the live ticket in the page layout.
 */
export const InitTicketVideoCaptureStage = forwardRef<
  HTMLDivElement,
  InitTicketVideoCaptureStageProps
>(function InitTicketVideoCaptureStage({ children, className }, ref) {
  const viewportRef = useRef<HTMLDivElement>(null)

  useImperativeHandle(ref, () => viewportRef.current as HTMLDivElement)

  return (
    <div className={cn('relative mx-auto w-full max-w-[820px]', className)}>
      <div className="relative w-full aspect-video">
        <div
          ref={viewportRef}
          data-init-ticket-video-capture
          className="absolute inset-0 overflow-hidden rounded-2xl"
        >
          <div className="relative z-10 flex h-full w-full items-center justify-center px-4 py-0.5 sm:px-6 sm:py-1">
            <div
              className="mx-auto w-full origin-center scale-[0.94] sm:scale-[0.98]"
              style={{ maxWidth: INIT_TICKET_MAX_WIDTH_PX }}
            >
              {children}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
})
