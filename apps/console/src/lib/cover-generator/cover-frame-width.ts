import { getCoverContentLayoutTransform } from '@/lib/cover-generator/cover-layout-scale'

export type CoverCanvasSize = {
  width: number
  height: number
}

/** Frame width as % of the current template (export canvas) width. */
export const COVER_SCREENSHOT_FRAME_WIDTH = {
  minPercent: 30,
  maxPercent: 100,
  flatDefaultPercent: 82,
  sideDefaultPercent: 58,
  angledDefaultPercent: 92,
  step: 1,
} as const

/** Minimum browser shell height in artboard pixels. */
export const COVER_SCREENSHOT_FRAME_MIN_HEIGHT_PX = 200

/** Frame height as % of the space below the title (100 = tallest frame without overlapping). */
export const COVER_SCREENSHOT_FRAME_HEIGHT = {
  minPercent: 0,
  maxPercent: 100,
  defaultPercent: 100,
  sideDefaultPercent: 90,
  step: 1,
} as const

export function getCoverFrameWidthPx(
  frameWidthPercent: number,
  canvas: CoverCanvasSize,
): number {
  const { scale } = getCoverContentLayoutTransform(canvas.width, canvas.height)
  const targetExportPx = (canvas.width * frameWidthPercent) / 100
  return Math.max(1, Math.round(targetExportPx / scale))
}

export function clampCoverFrameWidthPercent(
  percent: number,
  limits: { minPercent: number; maxPercent: number } = COVER_SCREENSHOT_FRAME_WIDTH,
): number {
  return Math.min(limits.maxPercent, Math.max(limits.minPercent, Math.round(percent)))
}

/** Map height % to export px within the space below the title block. */
export function resolveCoverScreenshotFrameHeightExportPx(
  frameHeightPercent: number,
  maxFrameHeightExport: number,
  minFrameHeightExport: number,
): number {
  const max = Math.max(minFrameHeightExport, maxFrameHeightExport)
  const min = Math.min(minFrameHeightExport, max)
  const percent = clampCoverFrameHeightPercent(frameHeightPercent)
  return Math.round(min + (max - min) * (percent / 100))
}

export function clampCoverFrameHeightPercent(
  percent: number,
  limits: { minPercent: number; maxPercent: number } = COVER_SCREENSHOT_FRAME_HEIGHT,
): number {
  return Math.min(limits.maxPercent, Math.max(limits.minPercent, Math.round(percent)))
}
