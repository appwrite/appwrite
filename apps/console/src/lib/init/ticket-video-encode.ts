import {
  INIT_TICKET_VIDEO_CLIP_DURATION_SEC,
  INIT_TICKET_VIDEO_EXPORT_FPS,
} from '@/lib/init/ticket-video-capture'
import { sleep } from '@/lib/init/ticket-video-wall-clock-loop'

export type InitTicketVideoFileExtension = 'mp4' | 'webm'

const MEDIA_RECORDER_BITRATE = 12_000_000

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

export type InitTicketVideoRecording = {
  blob: Blob
  fileExtension: InitTicketVideoFileExtension
}

function getPreferredMediaRecorderFormat():
  | { mimeType: string; fileExtension: InitTicketVideoFileExtension }
  | undefined {
  if (typeof MediaRecorder === 'undefined') return undefined
  return VIDEO_FORMAT_CANDIDATES.find((format) =>
    MediaRecorder.isTypeSupported(format.mimeType),
  )
}

export function canUseInitTicketVideoMediaRecorder(): boolean {
  if (typeof HTMLCanvasElement === 'undefined') return false
  if (typeof HTMLCanvasElement.prototype.captureStream !== 'function') return false
  return Boolean(getPreferredMediaRecorderFormat())
}

export function canUseInitTicketVideoWebCodecs(): boolean {
  return typeof VideoEncoder === 'function'
}

type EncodeFramesOptions = {
  canvas: HTMLCanvasElement
  drawFrame: (progress: number) => void
  onProgress?: (progress: number) => void
}

function totalFrameCount() {
  return Math.max(
    1,
    Math.round(INIT_TICKET_VIDEO_CLIP_DURATION_SEC * INIT_TICKET_VIDEO_EXPORT_FPS),
  )
}

async function encodeWithMediabunny({
  canvas,
  drawFrame,
  onProgress,
}: EncodeFramesOptions): Promise<InitTicketVideoRecording> {
  const {
    BufferTarget,
    CanvasSource,
    Mp4OutputFormat,
    Output,
    Quality,
    WebMOutputFormat,
    getFirstEncodableVideoCodec,
  } = await import('mediabunny')

  const width = canvas.width
  const height = canvas.height
  const quality = new Quality('high')
  const codec = await getFirstEncodableVideoCodec(['avc', 'vp9', 'av1', 'vp8'], {
    width,
    height,
    quality,
  })

  if (!codec) {
    throw new Error('WebCodecs cannot encode this video')
  }

  const useMp4 = codec === 'avc' || codec === 'av1'
  const target = new BufferTarget()
  const output = new Output({
    format: useMp4
      ? new Mp4OutputFormat({ fastStart: 'in-memory' })
      : new WebMOutputFormat(),
    target,
  })
  const source = new CanvasSource(canvas, {
    codec,
    quality,
    latencyMode: 'quality',
    keyFrameInterval: 2,
  })
  output.addVideoTrack(source, { frameRate: INIT_TICKET_VIDEO_EXPORT_FPS })
  await output.start()

  const totalFrames = totalFrameCount()
  const frameDuration = 1 / INIT_TICKET_VIDEO_EXPORT_FPS

  try {
    for (let frameIndex = 0; frameIndex < totalFrames; frameIndex += 1) {
      const progress = totalFrames <= 1 ? 1 : frameIndex / (totalFrames - 1)
      drawFrame(progress)
      await source.add(frameIndex * frameDuration, frameDuration)
      onProgress?.(progress)
      if (frameIndex % 8 === 7) {
        await sleep(0)
      }
    }
    source.close()
    await output.finalize()
  } catch (error) {
    output.cancel().catch(() => undefined)
    throw error
  }

  if (!target.buffer) {
    throw new Error('Video encoding produced an empty file')
  }

  const fileExtension: InitTicketVideoFileExtension = useMp4 ? 'mp4' : 'webm'
  const mimeType = useMp4 ? 'video/mp4' : 'video/webm'
  return {
    blob: new Blob([target.buffer], { type: mimeType }),
    fileExtension,
  }
}

async function encodeWithMediaRecorder({
  canvas,
  drawFrame,
  onProgress,
}: EncodeFramesOptions): Promise<InitTicketVideoRecording> {
  const format = getPreferredMediaRecorderFormat()
  if (!format) {
    throw new Error('Video recording is not supported in this browser')
  }

  const stream = canvas.captureStream(INIT_TICKET_VIDEO_EXPORT_FPS)
  const recorder = new MediaRecorder(stream, {
    mimeType: format.mimeType,
    videoBitsPerSecond: MEDIA_RECORDER_BITRATE,
  })
  const chunks: Blob[] = []
  recorder.ondataavailable = (event) => {
    if (event.data.size > 0) chunks.push(event.data)
  }

  const recordingFinished = new Promise<InitTicketVideoRecording>(
    (resolve, reject) => {
      recorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop())
        resolve({
          blob: new Blob(chunks, { type: format.mimeType }),
          fileExtension: format.fileExtension,
        })
      }
      recorder.onerror = () => {
        stream.getTracks().forEach((track) => track.stop())
        reject(new Error('Recording failed'))
      }
    },
  )

  const totalFrames = totalFrameCount()
  const frameIntervalMs = 1000 / INIT_TICKET_VIDEO_EXPORT_FPS
  recorder.start(100)
  const playbackStart = performance.now()

  for (let frameIndex = 0; frameIndex < totalFrames; frameIndex += 1) {
    const progress = totalFrames <= 1 ? 1 : frameIndex / (totalFrames - 1)
    drawFrame(progress)
    onProgress?.(progress)
    const targetTime = playbackStart + (frameIndex + 1) * frameIntervalMs
    const waitMs = Math.max(0, targetTime - performance.now())
    if (waitMs > 0) {
      await sleep(waitMs)
    }
  }

  await sleep(120)
  if (recorder.state !== 'inactive') {
    recorder.stop()
  }

  return recordingFinished
}

export async function encodeInitTicketVideoFromCanvas(
  options: EncodeFramesOptions,
): Promise<InitTicketVideoRecording> {
  if (canUseInitTicketVideoWebCodecs()) {
    try {
      return await encodeWithMediabunny(options)
    } catch (error) {
      if (!canUseInitTicketVideoMediaRecorder()) throw error
    }
  }

  return encodeWithMediaRecorder(options)
}
