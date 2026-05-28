import {
  INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT,
  INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC,
  INIT_TICKET_VIDEO_CLIP_DURATION_SEC,
  INIT_TICKET_VIDEO_EXPORT_FPS,
} from '@/lib/init/ticket-video-capture'
import type { InitTicketVideoRecording } from '@/lib/init/record-init-ticket-video'
import { sleep } from '@/lib/init/ticket-video-wall-clock-loop'

const VIDEO_BITRATE = 35_000_000

async function seekVideoTo(video: HTMLVideoElement, time: number) {
  if (Math.abs(video.currentTime - time) < 0.001) return

  await new Promise<void>((resolve, reject) => {
    const onSeeked = () => {
      cleanup()
      resolve()
    }
    const onError = () => {
      cleanup()
      reject(new Error('Could not seek video'))
    }
    const cleanup = () => {
      video.removeEventListener('seeked', onSeeked)
      video.removeEventListener('error', onError)
    }
    video.addEventListener('seeked', onSeeked)
    video.addEventListener('error', onError)
    video.currentTime = time
  })
}

/**
 * Time-compresses a wall-clock tab recording into a fixed-length export clip.
 * Frame-by-frame seek encoding avoids rAF / playback pauses when the tab is hidden.
 */
export async function compressInitTicketRecordingToClip({
  blob,
  mimeType,
  fileExtension,
  sourceDurationSec = INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC,
  onProgress,
}: {
  blob: Blob
  mimeType: string
  fileExtension: InitTicketVideoRecording['fileExtension']
  sourceDurationSec?: number
  onProgress?: (progress: number) => void
}): Promise<InitTicketVideoRecording> {
  const clipDurationSec = INIT_TICKET_VIDEO_CLIP_DURATION_SEC
  const targetFps = INIT_TICKET_VIDEO_EXPORT_FPS
  const totalFrames = Math.max(1, Math.round(clipDurationSec * targetFps))
  const frameIntervalMs = 1000 / targetFps

  const video = document.createElement('video')
  video.muted = true
  video.playsInline = true
  video.preload = 'auto'

  const url = URL.createObjectURL(blob)

  try {
    video.src = url
    await new Promise<void>((resolve, reject) => {
      video.addEventListener('loadedmetadata', () => resolve(), { once: true })
      video.addEventListener(
        'error',
        () => reject(new Error('Could not load recording')),
        { once: true },
      )
    })

    const metadataDuration =
      Number.isFinite(video.duration) && video.duration > 0
        ? video.duration
        : sourceDurationSec

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth || 1920
    canvas.height = video.videoHeight || 1080
    const ctx = canvas.getContext('2d', { alpha: false })
    if (!ctx) throw new Error('Could not create encode canvas')

    const stream = canvas.captureStream(targetFps)
    const recorder = new MediaRecorder(stream, {
      mimeType,
      videoBitsPerSecond: VIDEO_BITRATE,
    })

    const chunks: Blob[] = []
    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) chunks.push(event.data)
    }

    const recordingFinished = new Promise<InitTicketVideoRecording>((resolve, reject) => {
      recorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop())
        resolve({
          blob: new Blob(chunks, { type: mimeType }),
          fileExtension,
        })
      }
      recorder.onerror = () => {
        reject(recorder.error ?? new Error('Could not encode video'))
      }
    })

    recorder.start(100)

    const encodeStart = performance.now()
    for (let frameIndex = 0; frameIndex < totalFrames; frameIndex += 1) {
      const outputProgress = totalFrames <= 1 ? 1 : frameIndex / (totalFrames - 1)
      const sourceTime = Math.min(
        metadataDuration,
        Math.max(0, outputProgress * metadataDuration),
      )

      await seekVideoTo(video, sourceTime)
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
      onProgress?.(
        INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT +
          outputProgress * (1 - INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT),
      )

      const targetTime = encodeStart + (frameIndex + 1) * frameIntervalMs
      const waitMs = Math.max(0, targetTime - performance.now())
      if (waitMs > 0) {
        await sleep(waitMs)
      }
    }

    onProgress?.(1)
    await sleep(120)

    if (recorder.state !== 'inactive') {
      recorder.stop()
    }

    return recordingFinished
  } finally {
    URL.revokeObjectURL(url)
    video.removeAttribute('src')
    video.load()
  }
}
