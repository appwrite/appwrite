import { COVER_WIDTH } from '@/lib/cover-generator/constants'

/** Default 3D card transform for perspective product mockups. */
export const PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM = {
  perspective: 1400,
  rotateX: 58,
  rotateZ: -36,
  rotateY: 0,
  translateX: 0,
  translateY: -40,
} as const

export const COVER_SCREENSHOT_ANGLED_CARD_TRANSFORM = {
  rotateX: 51,
  rotateZ: -19,
  rotateY: -2,
  translateX: 225,
  translateY: -25,
} as const

export const PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO = 16 / 9

export const COVER_SCREENSHOT_ANGLED_DEFAULTS = {
  frameWidthPercent: 92,
  /** Scales from the bottom-right corner to fill the right edge and bottom strip. */
  displayScale: 1.65,
} as const

export const PERSPECTIVE_SCREENSHOT_CARD_SURFACE = {
  borderRadius: 24,
  background: '#17171c',
  border: '1px solid rgba(255, 255, 255, 0.08)',
  boxShadow: '0 40px 100px rgba(0, 0, 0, 0.55)',
} as const

export function buildPerspectiveScreenshotCardTransform(options?: {
  rotateX?: number
  rotateY?: number
  rotateZ?: number
  translateX?: number
  translateY?: number
}): string {
  const rotateX = options?.rotateX ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateX
  const rotateY = options?.rotateY ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateY
  const rotateZ = options?.rotateZ ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateZ
  const translateX =
    options?.translateX ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.translateX
  const translateY =
    options?.translateY ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.translateY

  const rotateYPart = rotateY !== 0 ? ` rotateY(${rotateY}deg)` : ''
  const translateXPart = translateX !== 0 ? ` translateX(${translateX}px)` : ''
  const translateYPart = translateY !== 0 ? ` translateY(${translateY}px)` : ''

  return `rotateX(${rotateX}deg) rotateZ(${rotateZ}deg)${rotateYPart}${translateXPart}${translateYPart}`
}

/**
 * Flat 2D transform for @vercel/og/Satori (translate, rotate, skew, scale only).
 * Approximates the DOM 3D card tilt for export.
 */
export function buildPerspectiveScreenshotCardOgTransform(options?: {
  rotateX?: number
  rotateY?: number
  rotateZ?: number
  translateX?: number
  translateY?: number
}): string {
  const rotateX = options?.rotateX ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateX
  const rotateY = options?.rotateY ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateY
  const rotateZ = options?.rotateZ ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.rotateZ
  const translateX =
    options?.translateX ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.translateX
  const translateY =
    options?.translateY ?? PERSPECTIVE_SCREENSHOT_CARD_TRANSFORM.translateY

  const rx = (rotateX * Math.PI) / 180
  const scaleY = Math.cos(rx)
  const skewX = -rotateX * 0.38 + rotateY * 0.5

  const parts: string[] = []
  if (translateX !== 0 || translateY !== 0) {
    parts.push(`translate(${translateX}px, ${translateY}px)`)
  }
  if (rotateZ !== 0) {
    parts.push(`rotate(${rotateZ}deg)`)
  }
  if (Math.abs(skewX) > 0.01) {
    parts.push(`skewX(${skewX.toFixed(2)}deg)`)
  }
  if (Math.abs(scaleY - 1) > 0.001) {
    parts.push(`scaleY(${scaleY.toFixed(4)})`)
  }

  return parts.join(' ')
}

export function computePerspectiveScreenshotCardHeight(cardWidth: number): number {
  return Math.round(cardWidth / PERSPECTIVE_SCREENSHOT_CARD_ASPECT_RATIO)
}

/** Push past the bottom-right anchor so the card sits in the corner. */
export const COVER_SCREENSHOT_ANGLED_LAYOUT = {
  offsetXRatio: -0.13,
  offsetYRatio: -0.06,
} as const

export const COVER_SCREENSHOT_ANGLED_3D_DEFAULTS = {
  rotateX: COVER_SCREENSHOT_ANGLED_CARD_TRANSFORM.rotateX,
  rotateZ: COVER_SCREENSHOT_ANGLED_CARD_TRANSFORM.rotateZ,
  rotateY: COVER_SCREENSHOT_ANGLED_CARD_TRANSFORM.rotateY,
  translateX: COVER_SCREENSHOT_ANGLED_CARD_TRANSFORM.translateX,
  translateY: COVER_SCREENSHOT_ANGLED_CARD_TRANSFORM.translateY,
  displayScale: COVER_SCREENSHOT_ANGLED_DEFAULTS.displayScale,
  posXRatio: COVER_SCREENSHOT_ANGLED_LAYOUT.offsetXRatio,
  posYRatio: COVER_SCREENSHOT_ANGLED_LAYOUT.offsetYRatio,
} as const

export const COVER_SCREENSHOT_ANGLED_3D_LIMITS = {
  rotateX: { min: 15, max: 85, step: 1 },
  rotateZ: { min: -70, max: 10, step: 1 },
  rotateY: { min: -45, max: 45, step: 1 },
  translateX: { min: -300, max: 600, step: 5 },
  translateY: { min: -200, max: 400, step: 5 },
  displayScale: { min: 0.5, max: 4, step: 0.05 },
  posXRatio: { min: -0.6, max: 0.6, step: 0.01 },
  posYRatio: { min: -0.35, max: 0.75, step: 0.01 },
} as const

export function getCoverScreenshotAngledCardTransform(fields: {
  rotateX: number
  rotateY: number
  rotateZ: number
  translateX: number
  translateY: number
}) {
  return {
    rotateX: fields.rotateX,
    rotateY: fields.rotateY,
    rotateZ: fields.rotateZ,
    translateX: fields.translateX,
    translateY: fields.translateY,
  }
}

/** Scale factor from cover reference width (1200) to the active canvas width. */
export function getCoverScreenshotAngledCanvasScale(canvasWidth: number): number {
  return canvasWidth / COVER_WIDTH
}

/** Pixel offsets and perspective scale with canvas size; angles stay in degrees. */
export function scaleCoverScreenshotAngledCardTransform(
  transform: ReturnType<typeof getCoverScreenshotAngledCardTransform>,
  canvasScale: number,
) {
  if (canvasScale === 1) return transform

  return {
    ...transform,
    translateX: transform.translateX * canvasScale,
    translateY: transform.translateY * canvasScale,
  }
}

export function scaleCoverScreenshotAngledPerspective(
  perspective: number,
  canvasScale: number,
): number {
  return perspective * canvasScale
}

/** @deprecated Use computeCoverScreenshotAngledLayoutOffset */
export const PERSPECTIVE_SCREENSHOT_CARD_CLIP_LAYOUT = COVER_SCREENSHOT_ANGLED_LAYOUT

export function computeCoverScreenshotAngledLayoutOffset(
  containerWidth: number,
  containerHeight: number,
  position?: { posXRatio?: number; posYRatio?: number },
): { x: number; y: number } {
  const posXRatio = position?.posXRatio ?? COVER_SCREENSHOT_ANGLED_LAYOUT.offsetXRatio
  const posYRatio = position?.posYRatio ?? COVER_SCREENSHOT_ANGLED_LAYOUT.offsetYRatio

  return {
    x: Math.round(containerWidth * posXRatio),
    y: Math.round(containerHeight * posYRatio),
  }
}

/** Absolute layout for OG/Satori (no calc() or % in transforms). */
export function computeCoverScreenshotAngledOgLayout(options: {
  containerWidth: number
  containerHeight: number
  displayScale?: number
  posXRatio?: number
  posYRatio?: number
}) {
  const displayScale = options.displayScale ?? COVER_SCREENSHOT_ANGLED_DEFAULTS.displayScale
  const offset = computeCoverScreenshotAngledLayoutOffset(
    options.containerWidth,
    options.containerHeight,
    {
      posXRatio: options.posXRatio,
      posYRatio: options.posYRatio,
    },
  )

  return {
    bottom: 0,
    right: 0,
    transform: `translate(${offset.x}px, ${offset.y}px) scale(${displayScale})`,
    transformOrigin: '100% 100%',
  }
}

/** Absolute frame box for OG/Satori (explicit left/top; avoids right/bottom/percent sizing). */
export function computeCoverScreenshotAngledOgFrameBox(options: {
  containerWidth: number
  containerHeight: number
  frameWidth: number
  frameHeight: number
  displayScale?: number
  posXRatio?: number
  posYRatio?: number
}) {
  const displayScale = options.displayScale ?? COVER_SCREENSHOT_ANGLED_DEFAULTS.displayScale
  const offset = computeCoverScreenshotAngledLayoutOffset(
    options.containerWidth,
    options.containerHeight,
    {
      posXRatio: options.posXRatio,
      posYRatio: options.posYRatio,
    },
  )

  const originX = options.frameWidth * 0.2
  const originY = options.frameHeight * 0.2
  const anchorLeft = options.containerWidth - options.frameWidth
  const anchorTop = options.containerHeight - options.frameHeight

  return {
    left: Math.round(anchorLeft - originX * (displayScale - 1) + offset.x),
    top: Math.round(anchorTop - originY * (displayScale - 1) + offset.y),
    width: options.frameWidth,
    height: options.frameHeight,
    scale: displayScale,
    transformOrigin: '20% 20%',
  }
}

/** @deprecated Use computeCoverScreenshotAngledLayoutOffset */
export function computePerspectiveScreenshotCardClipOffset(
  containerWidth: number,
  containerHeight: number,
): { x: number; y: number } {
  return computeCoverScreenshotAngledLayoutOffset(containerWidth, containerHeight)
}
