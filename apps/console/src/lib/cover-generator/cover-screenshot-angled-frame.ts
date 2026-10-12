import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  getCoverFrameWidthPx,
} from '@/lib/cover-generator/cover-frame-width'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotFrameLayout,
} from '@/lib/cover-generator/cover-screenshot-frame'
import {
  COVER_SCREENSHOT_ANGLED_3D_DEFAULTS,
  COVER_SCREENSHOT_ANGLED_DEFAULTS,
  PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO,
  PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM,
} from '@/lib/perspective-screenshot-card/constants'
import type { CoverRenderData } from '@/lib/cover-generator/types'

export { COVER_SCREENSHOT_ANGLED_DEFAULTS }

export type CoverScreenshotAngledLayoutData = Extract<
  CoverRenderData,
  { template: 'screenshot-angled' }
>

export function getCoverScreenshotAngledShellDimensions(frameWidth: number) {
  const { paddingX, paddingTop, chromeHeight } = COVER_HERO_SCREENSHOT_FRAME
  const innerWidth = Math.max(1, frameWidth - paddingX * 2)
  const innerHeight = Math.round(innerWidth / PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO)
  const shellHeight = paddingTop + chromeHeight + innerHeight

  return {
    shellWidth: frameWidth,
    shellHeight,
    innerWidth,
    innerHeight,
    frameHeight: shellHeight,
  }
}

function resolveAngledFrameWidthPx(data: CoverScreenshotAngledLayoutData): number {
  return getCoverFrameWidthPx(data.frameWidthPercent, {
    width: data.width,
    height: data.height,
  })
}

export function getCoverScreenshotAngledInnerDimensions(
  data: CoverScreenshotAngledLayoutData,
): { width: number; height: number } {
  const { innerWidth, innerHeight } = getCoverScreenshotAngledShellDimensions(
    resolveAngledFrameWidthPx(data),
  )
  return {
    width: innerWidth,
    height: innerHeight,
  }
}

export function getCoverScreenshotAngledFrameLayout(data: CoverScreenshotAngledLayoutData) {
  const frameWidth = resolveAngledFrameWidthPx(data)
  const { shellHeight } = getCoverScreenshotAngledShellDimensions(frameWidth)
  return getCoverScreenshotFrameLayout(
    {
      frameWidthPercent: data.frameWidthPercent,
      width: data.width,
      height: data.height,
    },
    0,
    0,
    shellHeight,
  )
}

export function getCoverScreenshotAngledLayoutResetFields() {
  return {
    frameWidthPercent: COVER_SCREENSHOT_ANGLED_DEFAULTS.frameWidthPercent,
    zoom: 1,
    focusX: 0,
    focusY: 0,
    ...COVER_SCREENSHOT_ANGLED_3D_DEFAULTS,
  }
}

export function getCoverScreenshotAngledSceneLayout(data: CoverScreenshotAngledLayoutData) {
  const { shellWidth, shellHeight } = getCoverScreenshotAngledShellDimensions(
    resolveAngledFrameWidthPx(data),
  )

  return {
    cardWidth: shellWidth,
    cardHeight: shellHeight,
    transform: PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM,
    canvasWidth: COVER_WIDTH,
    canvasHeight: COVER_HEIGHT,
  }
}
