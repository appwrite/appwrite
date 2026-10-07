import { captureInitTicketStillCanvas } from '@/lib/init/ticket-video-still'
import { createInitTicketVideoCompositor } from '@/lib/init/ticket-video-compositor'
import {
  canUseInitTicketVideoMediaRecorder,
  canUseInitTicketVideoWebCodecs,
  encodeInitTicketVideoFromCanvas,
  type InitTicketVideoRecording,
} from '@/lib/init/ticket-video-encode'
import {
  INIT_TICKET_VIDEO_EXPORT_HEIGHT,
  INIT_TICKET_VIDEO_EXPORT_WIDTH,
  INIT_TICKET_VIDEO_STILL_PROGRESS_WEIGHT,
  getInitTicketTiltForProgress,
} from '@/lib/init/ticket-video-capture'
import { resolveCssColor } from '@/lib/init/parse-css-accent'
import { sleep, withInitTicketCaptureWakeLock } from '@/lib/init/ticket-video-wall-clock-loop'

export type {
  InitTicketVideoFileExtension,
  InitTicketVideoRecording,
} from '@/lib/init/ticket-video-encode'

export { getInitTicketTiltForProgress }

export function isInitTicketVideoExportSupported(): boolean {
  if (typeof window === 'undefined') return false
  if (typeof HTMLCanvasElement === 'undefined') return false
  return canUseInitTicketVideoWebCodecs() || canUseInitTicketVideoMediaRecorder()
}

export function waitForNextPaint() {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
    return sleep(32)
  }

  return new Promise<void>((resolve) => {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => resolve())
    })
  })
}

export function downloadInitTicketVideo(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.rel = 'noopener'
  anchor.click()
  URL.revokeObjectURL(url)
}

export type RecordInitTicketVideoOptions = {
  ticketElement: HTMLElement
  ticketBackElement?: HTMLElement | null
  setTilt: (x: number, y: number) => void
  resetTilt: () => void
  backgroundColor?: string
  borderColor?: string
  usesDarkChrome?: boolean
  onProgress?: (progress: number) => void
  /** Fired after the still is captured, before frame encoding. */
  onVisibleCaptureComplete?: () => void
}

function readCssColor(
  element: HTMLElement,
  customProperty: string,
  fallback: string,
) {
  const resolved = resolveCssColor(`var(${customProperty})`, element)
  return resolved || fallback
}

export async function recordInitTicketVideo({
  ticketElement,
  ticketBackElement,
  setTilt,
  resetTilt,
  backgroundColor,
  borderColor,
  usesDarkChrome,
  onProgress,
  onVisibleCaptureComplete,
}: RecordInitTicketVideoOptions): Promise<InitTicketVideoRecording> {
  if (!isInitTicketVideoExportSupported()) {
    throw new Error('Video recording is not supported in this browser')
  }

  return withInitTicketCaptureWakeLock(async () => {
    setTilt(0, 0)
    await waitForNextPaint()
    onProgress?.(0)

    const ticketStill = await captureInitTicketStillCanvas(ticketElement)
    const layoutWidth = Math.round(ticketStill.width / 2)
    const layoutHeight = Math.round(ticketStill.height / 2)
    const stillMidpoint =
      INIT_TICKET_VIDEO_STILL_PROGRESS_WEIGHT *
      (ticketBackElement ? 0.5 : 1)
    onProgress?.(stillMidpoint)

    if (ticketBackElement) {
      await waitForNextPaint()
    }

    const ticketBackStill = ticketBackElement
      ? await captureInitTicketStillCanvas(ticketBackElement, {
          face: 'back',
          layoutSize: { width: layoutWidth, height: layoutHeight },
        })
      : undefined

    if (ticketBackStill && (ticketBackStill.width < 2 || ticketBackStill.height < 2)) {
      throw new Error('Ticket back still capture failed')
    }
    onProgress?.(INIT_TICKET_VIDEO_STILL_PROGRESS_WEIGHT)
    onVisibleCaptureComplete?.()
    resetTilt()

    const root = document.documentElement
    const compositor = createInitTicketVideoCompositor({
      ticketImage: ticketStill,
      ticketBackImage: ticketBackStill,
      ticketWidth: ticketStill.width,
      ticketHeight: ticketStill.height,
      width: INIT_TICKET_VIDEO_EXPORT_WIDTH,
      height: INIT_TICKET_VIDEO_EXPORT_HEIGHT,
      backgroundColor:
        backgroundColor ??
        readCssColor(root, '--background', '#09090b'),
      borderColor: borderColor ?? readCssColor(root, '--border', '#27272a'),
      usesDarkChrome,
    })

    try {
      const recording = await encodeInitTicketVideoFromCanvas({
        canvas: compositor.canvas,
        drawFrame: compositor.drawFrame,
        onProgress: (encodeProgress) => {
          onProgress?.(
            INIT_TICKET_VIDEO_STILL_PROGRESS_WEIGHT +
              encodeProgress * (1 - INIT_TICKET_VIDEO_STILL_PROGRESS_WEIGHT),
          )
        },
      })
      onProgress?.(1)
      return recording
    } finally {
      compositor.dispose()
    }
  })
}
