import { forwardRef, useImperativeHandle, useRef, type ReactNode } from 'react'
import { InitHeroBackground } from '@/components/pages/init/_components/InitHeroBackground'
import { INIT_TICKET_MAX_WIDTH_PX } from '@/lib/init/ticket-layout'
import {
  INIT_TICKET_VIDEO_DOT_PATTERN_CLASS,
  INIT_TICKET_VIDEO_HERO_MOTION_SPEED,
  INIT_TICKET_VIDEO_SURFACE_CLASS,
} from '@/lib/init/ticket-video-capture'
import { cn } from '@/lib/utils'

type InitTicketVideoCaptureStageProps = {
  children: ReactNode
  className?: string
  /** UI-only chrome while frames are being captured (sibling, not in the video). */
  showRecordingChrome?: boolean
  /** Hero particle animation behind the ticket (included in the export). */
  showHeroAnimation?: boolean
  /** Matches ticket underscore / ID accent. */
  ticketAccentColor?: string
  /** Gold / silver tickets use dark particle curves. */
  ticketUsesDarkChrome?: boolean
}

/**
 * Visible stage for ticket video export: 16:9 dotted viewport + ticket.
 * `ref` is the viewport only so the recording overlay matches the exported frame.
 */
export const InitTicketVideoCaptureStage = forwardRef<
  HTMLDivElement,
  InitTicketVideoCaptureStageProps
>(function InitTicketVideoCaptureStage(
  {
    children,
    className,
    showRecordingChrome = false,
    showHeroAnimation = false,
    ticketAccentColor,
    ticketUsesDarkChrome = false,
  },
  ref,
) {
  const viewportRef = useRef<HTMLDivElement>(null)

  useImperativeHandle(ref, () => viewportRef.current as HTMLDivElement)

  const isRecording = showRecordingChrome || showHeroAnimation

  return (
    <div className={cn('relative mx-auto w-full max-w-[820px]', className)}>
      <div className="relative w-full aspect-video">
        <InitTicketRecordingChrome visible={showRecordingChrome} />

        <div
          ref={viewportRef}
          data-init-ticket-video-capture
          className={cn(
            'absolute inset-0 overflow-hidden rounded-2xl transition-[box-shadow] duration-200',
            showRecordingChrome &&
              'shadow-[0_0_0_2px_color-mix(in_srgb,var(--destructive)_55%,transparent),0_0_0_1px_color-mix(in_srgb,var(--destructive)_25%,transparent),0_0_32px_-8px_color-mix(in_srgb,var(--destructive)_35%,transparent)]',
          )}
        >
          {showHeroAnimation ? (
            <div className="absolute inset-0 z-0 overflow-hidden">
              <InitHeroBackground
                containerRef={viewportRef}
                compact={false}
                active={showHeroAnimation}
                bare
                accentColor={ticketAccentColor}
                particleIsDark={ticketUsesDarkChrome}
                particleMotionSpeed={INIT_TICKET_VIDEO_HERO_MOTION_SPEED}
                keepAliveWhenHidden={showHeroAnimation}
              />
            </div>
          ) : null}

          <div
            className={cn(
              'absolute inset-0 z-[1]',
              isRecording
                ? showHeroAnimation
                  ? 'bg-muted/30 dark:bg-background/40'
                  : INIT_TICKET_VIDEO_SURFACE_CLASS
                : 'bg-transparent',
            )}
            aria-hidden
          />
          {isRecording ? (
            <div
              className={cn(
                'pointer-events-none absolute inset-0 z-[2]',
                INIT_TICKET_VIDEO_DOT_PATTERN_CLASS,
              )}
              aria-hidden
            />
          ) : null}
          <div className="relative z-10 flex h-full w-full items-center justify-center px-4 py-0.5 sm:px-6 sm:py-1">
            <div
              className="w-full origin-center scale-[0.94] sm:scale-[0.98]"
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

function InitTicketRecordingChrome({ visible }: { visible: boolean }) {
  return (
    <div
      className={cn(
        'pointer-events-none absolute inset-0 z-30 rounded-2xl transition-opacity duration-200',
        visible ? 'opacity-100' : 'opacity-0',
      )}
      aria-hidden={!visible}
    >
      <div
        className={cn(
          'absolute start-3 top-3 flex items-center gap-2 rounded-full bg-red-500/10 px-3 py-1.5 text-[12px] font-semibold tracking-wide text-red-600 shadow-md backdrop-blur-sm sm:start-4 sm:top-4',
          visible ? 'opacity-100' : 'opacity-0',
        )}
      >
        <span className="relative flex size-2 shrink-0" aria-hidden>
          <span
            className={cn(
              'absolute inline-flex size-full rounded-full bg-red-500 opacity-70',
              visible && 'animate-ping',
            )}
          />
          <span className="relative inline-flex size-2 rounded-full bg-red-500" />
        </span>
        <span>Capturing frames</span>
      </div>
    </div>
  )
}
