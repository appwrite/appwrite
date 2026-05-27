import {
  INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT,
  INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC,
  INIT_TICKET_VIDEO_CLIP_DURATION_SEC,
  INIT_TICKET_VIDEO_EXPORT_FPS,
} from '@/lib/init/ticket-video-capture'
import type { InitTicketVideoRecording } from '@/lib/init/record-init-ticket-video'

const VIDEO_BITRATE = 35_000_000

function sleep(ms: number) {
  return new Promise<void>((resolve) => {
    window.setTimeout(resolve, ms)
  })
}

/**
 * Time-compresses a wall-clock tab recording into a fixed-length export clip.
 * Playing the source faster yields denser motion samples in the output file.
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
  const clipDurationMs = clipDurationSec * 1000
  const targetFps = INIT_TICKET_VIDEO_EXPORT_FPS

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
    const playbackRate = metadataDuration / clipDurationSec

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
    video.playbackRate = playbackRate
    video.currentTime = 0

    try {
      await video.play()
    } catch {
      throw new Error('Could not encode video')
    }

    const encodeStart = performance.now()
    await new Promise<void>((resolve) => {
      const tick = () => {
        const elapsed = performance.now() - encodeStart
        if (elapsed >= clipDurationMs || video.ended || video.paused) {
          resolve()
          return
        }

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
        const progress = Math.min(1, elapsed / clipDurationMs)
        onProgress?.(
          INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT +
            progress * (1 - INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT),
        )
        requestAnimationFrame(tick)
      }
      requestAnimationFrame(tick)
    })

    video.pause()
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
