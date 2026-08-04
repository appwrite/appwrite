import '@/lib/init/init-ticket-video-element-capture.d'
import {
  INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT,
  INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC,
  INIT_TICKET_VIDEO_EXPORT_FPS,
} from '@/lib/init/ticket-video-capture'
import type { InitTicketVideoRecording } from '@/lib/init/record-init-ticket-video'
import { compressInitTicketRecordingToClip } from '@/lib/init/ticket-video-time-compress'
import {
  runVisibilityPausedWallClockLoop,
  sleep,
} from '@/lib/init/ticket-video-wall-clock-loop'

const CAPTURE_WALL_CLOCK_MS = INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC * 1000
/** ~6 Mbps per megapixel per second at 60fps - keeps edges clean on cropped stage. */
const VIDEO_BITRATE = 35_000_000

function getCropTargetConstructor():
  | { fromElement(element: Element): Promise<unknown> }
  | undefined {
  const globalScope = globalThis as typeof globalThis & {
    CropTarget?: { fromElement(element: Element): Promise<unknown> }
  }
  return globalScope.CropTarget
}

export function canRecordInitTicketViaElementCapture(): boolean {
  if (typeof navigator === 'undefined') return false
  if (typeof window !== 'undefined' && !window.isSecureContext) return false
  if (typeof navigator.mediaDevices?.getDisplayMedia !== 'function') return false
  if (typeof getCropTargetConstructor()?.fromElement !== 'function') return false
  if (typeof MediaStreamTrack === 'undefined') return false
  return 'cropTo' in MediaStreamTrack.prototype
}

type ElementCaptureRecordOptions = {
  captureElement: HTMLElement
  mimeType: string
  fileExtension: InitTicketVideoRecording['fileExtension']
  setTilt: (x: number, y: number) => void
  resetTilt: () => void
  getTiltForProgress: (t: number) => { x: number; y: number }
  onProgress?: (progress: number) => void
  onVisibleCaptureComplete?: () => void
}

async function apply60FpsVideoConstraints(track: MediaStreamTrack) {
  try {
    await track.applyConstraints({
      frameRate: { ideal: INIT_TICKET_VIDEO_EXPORT_FPS, min: 30, max: 60 },
    })
  } catch {
    // Some drivers cap below 60; stream is still real-time compositor capture.
  }
}

async function recordWallClockCapture({
  captureElement,
  mimeType,
  setTilt,
  getTiltForProgress,
  onProgress,
}: {
  captureElement: HTMLElement
  mimeType: string
  setTilt: (x: number, y: number) => void
  getTiltForProgress: (t: number) => { x: number; y: number }
  onProgress?: (progress: number) => void
}): Promise<Blob> {
  captureElement.scrollIntoView({ block: 'center', behavior: 'instant' })
  await sleep(80)

  let stream: MediaStream
  try {
    stream = await navigator.mediaDevices.getDisplayMedia({
      video: {
        frameRate: {
          ideal: INIT_TICKET_VIDEO_EXPORT_FPS,
          min: 30,
          max: INIT_TICKET_VIDEO_EXPORT_FPS,
        },
        width: { ideal: 1920 },
        height: { ideal: 1080 },
      },
      audio: false,
      preferCurrentTab: true,
      selfBrowserSurface: 'include',
      surfaceSwitching: 'exclude',
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'NotAllowedError') {
      throw new Error('Tab capture was cancelled')
    }
    throw error
  }

  const [videoTrack] = stream.getVideoTracks()
  if (!videoTrack?.cropTo) {
    stream.getTracks().forEach((track) => track.stop())
    throw new Error('Element crop is not supported in this browser')
  }

  try {
    const cropTarget = await getCropTargetConstructor()!.fromElement(captureElement)
    await videoTrack.cropTo(cropTarget)
    await apply60FpsVideoConstraints(videoTrack)
  } catch (error) {
    stream.getTracks().forEach((track) => track.stop())
    throw error
  }

  const recorder = new MediaRecorder(stream, {
    mimeType,
    videoBitsPerSecond: VIDEO_BITRATE,
  })

  const chunks: Blob[] = []
  recorder.ondataavailable = (event) => {
    if (event.data.size > 0) chunks.push(event.data)
  }

  const recordingFinished = new Promise<Blob>((resolve, reject) => {
    recorder.onstop = () => {
      stream.getTracks().forEach((track) => track.stop())
      resolve(new Blob(chunks, { type: mimeType }))
    }
    recorder.onerror = () => {
      stream.getTracks().forEach((track) => track.stop())
      reject(recorder.error ?? new Error('Recording failed'))
    }
  })

  setTilt(0, 0)
  await sleep(32)

  recorder.start(100)

  const pauseRecorder = () => {
    if (recorder.state === 'recording') {
      recorder.pause()
    }
  }

  const resumeRecorder = () => {
    if (recorder.state === 'paused') {
      recorder.resume()
    }
  }

  await runVisibilityPausedWallClockLoop({
    durationMs: CAPTURE_WALL_CLOCK_MS,
    onPause: pauseRecorder,
    onResume: resumeRecorder,
    onTick: (progress) => {
      onProgress?.(progress * INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT)
      const { x, y } = getTiltForProgress(progress)
      setTilt(x, y)
    },
  })

  await sleep(200)

  if (recorder.state !== 'inactive') {
    recorder.stop()
  }

  return recordingFinished
}

/**
 * Records the ticket stage at display refresh rate (target 60fps) via tab capture + crop,
 * then time-compresses into a 10s export for denser motion sampling.
 */
export async function recordInitTicketVideoViaElementCapture({
  captureElement,
  mimeType,
  fileExtension,
  setTilt,
  resetTilt,
  getTiltForProgress,
  onProgress,
  onVisibleCaptureComplete,
}: ElementCaptureRecordOptions): Promise<InitTicketVideoRecording> {
  const rawBlob = await recordWallClockCapture({
    captureElement,
    mimeType,
    setTilt,
    getTiltForProgress,
    onProgress,
  })

  onVisibleCaptureComplete?.()
  resetTilt()

  return compressInitTicketRecordingToClip({
    blob: rawBlob,
    mimeType,
    fileExtension,
    onProgress,
  })
}
