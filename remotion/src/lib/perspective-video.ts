/** Soft 3D tilt for framed browser product shots. */
export const BROWSER_CARD_TILT = {
  perspective: 3400,
  rotateX: 5,
  rotateY: 2,
  rotateZ: -1.5,
  translateX: 0,
  translateY: 4,
  scale: 1,
} as const

export function buildBrowserCardTransform(options?: {
  rotateX?: number
  rotateY?: number
  rotateZ?: number
  translateX?: number
  translateY?: number
  scale?: number
}): string {
  const {
    rotateX = BROWSER_CARD_TILT.rotateX,
    rotateY = BROWSER_CARD_TILT.rotateY,
    rotateZ = BROWSER_CARD_TILT.rotateZ,
    translateX = BROWSER_CARD_TILT.translateX,
    translateY = BROWSER_CARD_TILT.translateY,
    scale = BROWSER_CARD_TILT.scale,
  } = options ?? {}

  return [
    `rotateX(${rotateX}deg)`,
    `rotateY(${rotateY}deg)`,
    `rotateZ(${rotateZ}deg)`,
    translateX !== 0 ? `translateX(${translateX}px)` : null,
    translateY !== 0 ? `translateY(${translateY}px)` : null,
    scale !== 1 ? `scale(${scale})` : null,
  ]
    .filter(Boolean)
    .join(' ')
}

/** @deprecated Use buildBrowserCardTransform */
export const TILTED_VIDEO_TRANSFORM = BROWSER_CARD_TILT
export const buildTiltedVideoTransform = buildBrowserCardTransform
