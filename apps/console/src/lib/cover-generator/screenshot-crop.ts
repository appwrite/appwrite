import type { CoverScreenshotFields } from '@/lib/cover-generator/types'

export type ScreenshotCropRect = {
  left: number
  top: number
  width: number
  height: number
}

function clampPercent(value: number): number {
  return Math.min(100, Math.max(0, value))
}

/**
 * Crop region with the frame aspect ratio, positioned by focus X/Y.
 * Matches CSS object-fit: cover + object-position behavior.
 */
export function getScreenshotCropRect(
  naturalWidth: number,
  naturalHeight: number,
  outputWidth: number,
  outputHeight: number,
  data: Pick<CoverScreenshotFields, 'zoom' | 'focusX' | 'focusY'>,
): ScreenshotCropRect {
  const outputAspect = outputWidth / outputHeight
  const zoom = Math.max(1, data.zoom)

  let cropHeight = naturalHeight / zoom
  let cropWidth = cropHeight * outputAspect

  if (cropWidth > naturalWidth) {
    cropWidth = naturalWidth / zoom
    cropHeight = cropWidth / outputAspect
  }

  cropWidth = Math.max(1, Math.min(Math.round(cropWidth), naturalWidth))
  cropHeight = Math.max(1, Math.min(Math.round(cropHeight), naturalHeight))

  const maxLeft = Math.max(0, naturalWidth - cropWidth)
  const maxTop = Math.max(0, naturalHeight - cropHeight)
  const left = Math.round(maxLeft * (clampPercent(data.focusX) / 100))
  const top = Math.round(maxTop * (clampPercent(data.focusY) / 100))

  return {
    left,
    top,
    width: Math.min(cropWidth, naturalWidth - left),
    height: Math.min(cropHeight, naturalHeight - top),
  }
}

export function drawScreenshotCropCover(
  ctx: CanvasRenderingContext2D,
  image: CanvasImageSource,
  crop: ScreenshotCropRect,
  outputWidth: number,
  outputHeight: number,
): void {
  ctx.drawImage(
    image,
    crop.left,
    crop.top,
    crop.width,
    crop.height,
    0,
    0,
    outputWidth,
    outputHeight,
  )
}
