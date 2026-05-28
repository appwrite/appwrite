import {
  canRecordInitTicketViaElementCapture,
  recordInitTicketVideoViaElementCapture,
} from '@/lib/init/record-init-ticket-video-element-capture'
import {
  canRecordInitTicketViaCanvas,
  recordInitTicketVideoViaCanvas,
} from '@/lib/init/record-init-ticket-video-canvas'
import {
  sleep,
  withInitTicketCaptureWakeLock,
} from '@/lib/init/ticket-video-wall-clock-loop'
import { INIT_TICKET_VIDEO_MOTION_LOOP_CYCLES } from '@/lib/init/ticket-video-capture'
import { preloadInitTicketCaptureFonts } from '@/lib/init/ticket-font-embed'

const CAPTURE_TILT_MAX_X = 22
const CAPTURE_TILT_MAX_Y = 16
const EXPORT_WIDTH_PX = 820

export type InitTicketVideoFileExtension = 'mp4' | 'webm'

const VIDEO_FORMAT_CANDIDATES: Array<{
  mimeType: string
  fileExtension: InitTicketVideoFileExtension
}> = [
  { mimeType: 'video/mp4;codecs=avc1', fileExtension: 'mp4' },
  { mimeType: 'video/mp4;codecs="avc1.42E01E,mp4a.40.2"', fileExtension: 'mp4' },
  { mimeType: 'video/mp4', fileExtension: 'mp4' },
  { mimeType: 'video/webm;codecs=vp9', fileExtension: 'webm' },
  { mimeType: 'video/webm;codecs=vp8', fileExtension: 'webm' },
  { mimeType: 'video/webm', fileExtension: 'webm' },
]

function getPreferredVideoFormat():
  | { mimeType: string; fileExtension: InitTicketVideoFileExtension }
  | undefined {
  if (typeof MediaRecorder === 'undefined') return undefined
  return VIDEO_FORMAT_CANDIDATES.find((format) =>
    MediaRecorder.isTypeSupported(format.mimeType),
  )
}

/** True when Chrome/Edge tab element capture is available (~60fps). */
export function supportsInitTicket60FpsVideoCapture(): boolean {
  return canRecordInitTicketViaElementCapture()
}

export function isInitTicketVideoExportSupported(): boolean {
  if (typeof window === 'undefined') return false
  if (!getPreferredVideoFormat()) return false
  return canRecordInitTicketViaElementCapture() || canRecordInitTicketViaCanvas()
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

export function getInitTicketTiltForProgress(t: number) {
  const angle = t * Math.PI * 2 * INIT_TICKET_VIDEO_MOTION_LOOP_CYCLES
  return {
    x: Math.sin(angle) * CAPTURE_TILT_MAX_X * 0.92,
    y: Math.sin(angle * 0.92 + 0.45) * CAPTURE_TILT_MAX_Y,
  }
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
  captureElement: HTMLElement
  setTilt: (x: number, y: number) => void
  resetTilt: () => void
  backgroundColor?: string
  onProgress?: (progress: number) => void
  /** Canvas fallback: fired when live capture ends, before off-screen encode. */
  onVisibleCaptureComplete?: () => void
}

export type InitTicketVideoRecording = {
  blob: Blob
  fileExtension: InitTicketVideoFileExtension
}

export async function recordInitTicketVideo({
  captureElement,
  setTilt,
  resetTilt,
  backgroundColor,
  onProgress,
  onVisibleCaptureComplete,
}: RecordInitTicketVideoOptions): Promise<InitTicketVideoRecording> {
  const format = getPreferredVideoFormat()
  if (!format) {
    throw new Error('Video recording is not supported in this browser')
  }

  const tiltHandlers = {
    setTilt,
    resetTilt,
    getTiltForProgress: getInitTicketTiltForProgress,
    onProgress,
  }

  await preloadInitTicketCaptureFonts()

  return withInitTicketCaptureWakeLock(async () => {
    if (canRecordInitTicketViaElementCapture()) {
      return recordInitTicketVideoViaElementCapture({
        captureElement,
        mimeType: format.mimeType,
        fileExtension: format.fileExtension,
        ...tiltHandlers,
        onVisibleCaptureComplete,
      })
    }

    if (canRecordInitTicketViaCanvas()) {
      return recordInitTicketVideoViaCanvas({
        captureElement,
        mimeType: format.mimeType,
        fileExtension: format.fileExtension,
        exportWidthPx: EXPORT_WIDTH_PX,
        backgroundColor,
        ...tiltHandlers,
        onVisibleCaptureComplete,
      })
    }

    throw new Error('Video recording is not supported in this browser')
  })
}
