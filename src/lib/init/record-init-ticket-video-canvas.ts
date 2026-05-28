import { toCanvas, type Options } from 'html-to-image'
import { INIT_TICKET_ASPECT_RATIO } from '@/lib/init/ticket-layout'
import {
  buildInitTicketCaptureFontEmbedCss,
  preloadInitTicketCaptureFonts,
} from '@/lib/init/ticket-font-embed'
import {
  INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT,
  INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC,
  INIT_TICKET_VIDEO_CLIP_DURATION_SEC,
  INIT_TICKET_VIDEO_FALLBACK_FPS,
} from '@/lib/init/ticket-video-capture'
import type { InitTicketVideoRecording } from '@/lib/init/record-init-ticket-video'
import { runWallClockLoop, sleep } from '@/lib/init/ticket-video-wall-clock-loop'

const CAPTURE_WALL_CLOCK_MS = INIT_TICKET_VIDEO_CAPTURE_WALL_CLOCK_SEC * 1000
const FALLBACK_CAPTURE_PIXEL_RATIO = 1.5
const VIDEO_BITRATE = 20_000_000
const FRAME_PADDING_X_RATIO = 0.1
const FRAME_PADDING_Y_RATIO = 0.015

type CapturedSnapshot = {
  canvas: HTMLCanvasElement
  progress: number
}

export function canRecordInitTicketViaCanvas(): boolean {
  return (
    typeof HTMLCanvasElement !== 'undefined' &&
    typeof HTMLCanvasElement.prototype.captureStream === 'function'
  )
}

function isCaptureExcludedNode(node: Node) {
  return (
    node instanceof HTMLElement && node.dataset.initTicketCaptureExclude !== undefined
  )
}

function resolveCaptureSize(element: HTMLElement, fallbackWidth: number) {
  const rect = element.getBoundingClientRect()
  const width = rect.width > 0 ? Math.round(rect.width) : fallbackWidth
  const height =
    rect.height > 0
      ? Math.round(rect.height)
      : Math.round(fallbackWidth / INIT_TICKET_ASPECT_RATIO)
  return { width, height }
}

function cloneCanvasFrame(source: HTMLCanvasElement): HTMLCanvasElement {
  const clone = document.createElement('canvas')
  clone.width = source.width
  clone.height = source.height
  const ctx = clone.getContext('2d', { alpha: false })
  if (!ctx) throw new Error('Could not clone capture frame')
  ctx.drawImage(source, 0, 0)
  return clone
}

function pickSnapshotForProgress(
  snapshots: CapturedSnapshot[],
  progress: number,
): HTMLCanvasElement {
  if (snapshots.length === 0) {
    throw new Error('No frames were captured')
  }
  let best = snapshots[0]
  for (const snapshot of snapshots) {
    if (snapshot.progress <= progress) {
      best = snapshot
      continue
    }
    break
  }
  return best.canvas
}

async function preloadImages(element: HTMLElement) {
  const images = element.querySelectorAll('img')
  await Promise.all(
    Array.from(images).map(
      (img) =>
        new Promise<void>((resolve) => {
          if (img.complete && img.naturalWidth > 0) {
            resolve()
            return
          }
          const done = () => resolve()
          img.addEventListener('load', done, { once: true })
          img.addEventListener('error', done, { once: true })
        }),
    ),
  )
}

async function prepareCapturePipeline() {
  await preloadInitTicketCaptureFonts()
  return buildInitTicketCaptureFontEmbedCss()
}

async function warmUpFontCapture(
  captureElement: HTMLElement,
  captureOptions: Options,
) {
  try {
    await toCanvas(captureElement, captureOptions)
    await document.fonts.ready
    await waitForNextPaint()
  } catch {
    // Warm-up failure should not block export.
  }
}

function waitForNextPaint() {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
    return sleep(32)
  }
  return new Promise<void>((resolve) => {
    requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
  })
}

export type CanvasRecordOptions = {
  captureElement: HTMLElement
  mimeType: string
  fileExtension: InitTicketVideoRecording['fileExtension']
  exportWidthPx: number
  setTilt: (x: number, y: number) => void
  resetTilt: () => void
  getTiltForProgress: (t: number) => { x: number; y: number }
  backgroundColor?: string
  onProgress?: (progress: number) => void
  onVisibleCaptureComplete?: () => void
}

async function gatherSnapshots({
  captureElement,
  captureOptions,
  compositeCtx,
  outputCtx,
  outputWidth,
  outputHeight,
  sourceX,
  sourceY,
  compositeWidth,
  compositeHeight,
  bg,
  setTilt,
  getTiltForProgress,
  onProgress,
}: {
  captureElement: HTMLElement
  captureOptions: Options
  compositeCtx: CanvasRenderingContext2D
  outputCtx: CanvasRenderingContext2D
  outputWidth: number
  outputHeight: number
  sourceX: number
  sourceY: number
  compositeWidth: number
  compositeHeight: number
  bg: string
  setTilt: (x: number, y: number) => void
  getTiltForProgress: (t: number) => { x: number; y: number }
  onProgress?: (progress: number) => void
}): Promise<CapturedSnapshot[]> {
  const snapshots: CapturedSnapshot[] = []
  const startTime = performance.now()
  let stopped = false
  let captureChain: Promise<void> = Promise.resolve()

  const wallClockLoop = runWallClockLoop({
    durationMs: CAPTURE_WALL_CLOCK_MS,
    onTick: (progress) => {
      onProgress?.(progress * INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT)
      const { x, y } = getTiltForProgress(progress)
      setTilt(x, y)
    },
  })

  const runCaptureLoop = () => {
    if (stopped || performance.now() - startTime >= CAPTURE_WALL_CLOCK_MS) return
    captureChain = captureChain
      .then(async () => {
        if (stopped || performance.now() - startTime >= CAPTURE_WALL_CLOCK_MS) return
        const elapsed = performance.now() - startTime
        const progress = Math.min(1, elapsed / CAPTURE_WALL_CLOCK_MS)
        const { x, y } = getTiltForProgress(progress)
        setTilt(x, y)
        await waitForNextPaint()
        await document.fonts.ready

        const frameCanvas = await toCanvas(captureElement, captureOptions)
        compositeCtx.clearRect(0, 0, compositeWidth, compositeHeight)
        compositeCtx.fillStyle = bg
        compositeCtx.fillRect(0, 0, compositeWidth, compositeHeight)
        compositeCtx.drawImage(frameCanvas, sourceX, sourceY, outputWidth, outputHeight)
        outputCtx.clearRect(0, 0, outputWidth, outputHeight)
        outputCtx.drawImage(
          compositeCtx.canvas,
          sourceX,
          sourceY,
          outputWidth,
          outputHeight,
          0,
          0,
          outputWidth,
          outputHeight,
        )
        snapshots.push({
          canvas: cloneCanvasFrame(outputCtx.canvas),
          progress,
        })
      })
      .catch(() => undefined)
      .finally(() => {
        if (!stopped && performance.now() - startTime < CAPTURE_WALL_CLOCK_MS) {
          window.setTimeout(runCaptureLoop, 0)
        }
      })
  }

  runCaptureLoop()
  await wallClockLoop
  stopped = true
  await captureChain

  if (snapshots.length === 0) {
    throw new Error('No frames were captured')
  }

  return snapshots
}

async function replaySnapshotsToStream({
  snapshots,
  outputCtx,
  outputWidth,
  outputHeight,
  onProgress,
}: {
  snapshots: CapturedSnapshot[]
  outputCtx: CanvasRenderingContext2D
  outputWidth: number
  outputHeight: number
  onProgress?: (progress: number) => void
}): Promise<void> {
  const totalFrames = Math.max(
    1,
    Math.round(INIT_TICKET_VIDEO_CLIP_DURATION_SEC * INIT_TICKET_VIDEO_FALLBACK_FPS),
  )
  const frameIntervalMs = 1000 / INIT_TICKET_VIDEO_FALLBACK_FPS
  const playbackStart = performance.now()

  for (let frameIndex = 0; frameIndex < totalFrames; frameIndex += 1) {
    const progress =
      totalFrames <= 1 ? 1 : frameIndex / (totalFrames - 1)
    const snapshot = pickSnapshotForProgress(snapshots, progress)
    outputCtx.clearRect(0, 0, outputWidth, outputHeight)
    outputCtx.drawImage(snapshot, 0, 0, outputWidth, outputHeight)

    onProgress?.(
      INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT +
        progress * (1 - INIT_TICKET_VIDEO_CAPTURE_PROGRESS_WEIGHT),
    )

    const targetTime = playbackStart + (frameIndex + 1) * frameIntervalMs
    const waitMs = Math.max(0, targetTime - performance.now())
    if (waitMs > 0) {
      await sleep(waitMs)
    }
  }
}

/** Best-effort export when Element Capture is unavailable (not true 60fps). */
export async function recordInitTicketVideoViaCanvas({
  captureElement,
  mimeType,
  fileExtension,
  exportWidthPx,
  setTilt,
  resetTilt,
  getTiltForProgress,
  backgroundColor,
  onProgress,
  onVisibleCaptureComplete,
}: CanvasRecordOptions): Promise<InitTicketVideoRecording> {
  const { width: captureWidth, height: captureHeight } = resolveCaptureSize(
    captureElement,
    exportWidthPx,
  )

  const padX = Math.ceil(captureWidth * FRAME_PADDING_X_RATIO)
  const padY = Math.ceil(captureHeight * FRAME_PADDING_Y_RATIO)
  const outputWidth = Math.round(captureWidth * FALLBACK_CAPTURE_PIXEL_RATIO)
  const outputHeight = Math.round(captureHeight * FALLBACK_CAPTURE_PIXEL_RATIO)
  const compositeWidth = Math.round((captureWidth + padX * 2) * FALLBACK_CAPTURE_PIXEL_RATIO)
  const compositeHeight = Math.round((captureHeight + padY * 2) * FALLBACK_CAPTURE_PIXEL_RATIO)
  const sourceX = Math.round(padX * FALLBACK_CAPTURE_PIXEL_RATIO)
  const sourceY = Math.round(padY * FALLBACK_CAPTURE_PIXEL_RATIO)

  const compositeCanvas = document.createElement('canvas')
  compositeCanvas.width = compositeWidth
  compositeCanvas.height = compositeHeight
  const compositeCtx = compositeCanvas.getContext('2d', { alpha: false })
  if (!compositeCtx) throw new Error('Could not create composite canvas')

  const outputCanvas = document.createElement('canvas')
  outputCanvas.width = outputWidth
  outputCanvas.height = outputHeight
  const outputCtx = outputCanvas.getContext('2d', { alpha: false })
  if (!outputCtx) throw new Error('Could not create output canvas')

  const bg =
    backgroundColor ??
    getComputedStyle(captureElement).backgroundColor ??
    getComputedStyle(document.documentElement).backgroundColor ??
    '#09090b'

  const fontEmbedCSS = await prepareCapturePipeline()
  await preloadImages(captureElement)
  const captureOptions: Options = {
    width: captureWidth,
    height: captureHeight,
    canvasWidth: captureWidth,
    canvasHeight: captureHeight,
    pixelRatio: FALLBACK_CAPTURE_PIXEL_RATIO,
    cacheBust: false,
    includeQueryParams: false,
    skipAutoScale: true,
    backgroundColor: bg,
    preferredFontFormat: 'woff2',
    fontEmbedCSS,
    filter: (node) => !isCaptureExcludedNode(node),
    fetchRequestInit: { cache: 'force-cache' },
  }

  captureElement.scrollIntoView({ block: 'center', behavior: 'instant' })
  setTilt(0, 0)
  await waitForNextPaint()
  await warmUpFontCapture(captureElement, captureOptions)

  const snapshots = await gatherSnapshots({
    captureElement,
    captureOptions,
    compositeCtx,
    outputCtx,
    outputWidth,
    outputHeight,
    sourceX,
    sourceY,
    compositeWidth,
    compositeHeight,
    bg,
    setTilt,
    getTiltForProgress,
    onProgress,
  })

  onVisibleCaptureComplete?.()
  resetTilt()

  const stream = outputCanvas.captureStream(INIT_TICKET_VIDEO_FALLBACK_FPS)
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
      stream.getTracks().forEach((t) => t.stop())
      resolve({ blob: new Blob(chunks, { type: mimeType }), fileExtension })
    }
    recorder.onerror = () => {
      reject(recorder.error ?? new Error('Recording failed'))
    }
  })

  recorder.start(100)
  await replaySnapshotsToStream({
    snapshots,
    outputCtx,
    outputWidth,
    outputHeight,
    onProgress,
  })
  onProgress?.(1)
  await sleep(120)
  if (recorder.state !== 'inactive') recorder.stop()

  return recordingFinished
}
