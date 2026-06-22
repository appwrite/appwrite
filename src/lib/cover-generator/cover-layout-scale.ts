import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'

/** Display artboard width in the generator canvas at 100% zoom. */
export const COVER_ARTBOARD_DISPLAY_WIDTH = 720

export const COVER_LAYOUT_REFERENCE = {
  width: COVER_WIDTH,
  height: COVER_HEIGHT,
} as const

export type CoverContentLayoutTransform = {
  scale: number
  translateX: number
  translateY: number
}

export type CoverContentLayoutAnchor = 'center' | 'bottom' | 'right'

/**
 * Uniform fit scale for content laid out on the OG artboard (1200×630).
 * Never stretches: same scale on both axes. Horizontally centered; vertically
 * centered by default, or bottom-aligned for templates that sit flush on the edge.
 */
export function getCoverContentLayoutTransform(
  canvasWidth: number,
  canvasHeight: number,
  anchor: CoverContentLayoutAnchor = 'center',
): CoverContentLayoutTransform {
  const scale = Math.min(
    canvasWidth / COVER_LAYOUT_REFERENCE.width,
    canvasHeight / COVER_LAYOUT_REFERENCE.height,
  )
  const scaledWidth = COVER_LAYOUT_REFERENCE.width * scale
  const scaledHeight = COVER_LAYOUT_REFERENCE.height * scale

  return {
    scale,
    translateX:
      anchor === 'right'
        ? canvasWidth - scaledWidth
        : (canvasWidth - scaledWidth) / 2,
    translateY:
      anchor === 'bottom'
        ? canvasHeight - scaledHeight
        : (canvasHeight - scaledHeight) / 2,
  }
}

export type CoverArtboardRect = {
  x: number
  y: number
  width: number
  height: number
}

/** Map a rectangle from the 1200×630 artboard into export canvas space. */
export function transformCoverArtboardRect(
  rect: CoverArtboardRect,
  transform: CoverContentLayoutTransform,
): CoverArtboardRect {
  const { scale, translateX, translateY } = transform
  return {
    x: Math.round(rect.x * scale + translateX),
    y: Math.round(rect.y * scale + translateY),
    width: Math.max(1, Math.round(rect.width * scale)),
    height: Math.max(1, Math.round(rect.height * scale)),
  }
}

/** Map a Y coordinate from export canvas space into artboard space. */
export function coverExportYToArtboardY(
  exportY: number,
  transform: CoverContentLayoutTransform,
): number {
  return (exportY - transform.translateY) / transform.scale
}

export function getCoverDisplayScale(exportWidth: number): number {
  return COVER_ARTBOARD_DISPLAY_WIDTH / exportWidth
}

export function getCoverDisplayHeight(exportWidth: number, exportHeight: number): number {
  return Math.round(exportHeight * getCoverDisplayScale(exportWidth))
}
