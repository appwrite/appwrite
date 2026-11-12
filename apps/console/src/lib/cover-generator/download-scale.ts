import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import { getCoverImageExtension } from '@/lib/cover-generator/cover-image-format'

/** Matches parse-params width/height limits for export. */
export const COVER_MAX_EXPORT_DIMENSION = 4096

export const COVER_DOWNLOAD_SCALES = [1, 2] as const

export type CoverDownloadScale = (typeof COVER_DOWNLOAD_SCALES)[number]

export type CoverScaledDimensions = {
  width: number
  height: number
}

export function getCoverScaledDimensions(
  width: number,
  height: number,
  scale: CoverDownloadScale,
): CoverScaledDimensions | null {
  const scaledWidth = Math.round(width * scale)
  const scaledHeight = Math.round(height * scale)

  if (
    scaledWidth > COVER_MAX_EXPORT_DIMENSION ||
    scaledHeight > COVER_MAX_EXPORT_DIMENSION
  ) {
    return null
  }

  return { width: scaledWidth, height: scaledHeight }
}

export function formatCoverDownloadScaleLabel(scale: CoverDownloadScale): string {
  return `${scale}×`
}

export function formatCoverDimensionsLabel(width: number, height: number): string {
  return `${width} × ${height}`
}

export function buildCoverDownloadFilename(
  template: string,
  format: CoverImageFormat,
  scale: CoverDownloadScale,
  width: number,
  height: number,
): string {
  const extension = getCoverImageExtension(format)
  const scaleSuffix = scale === 1 ? '' : `-${scale}x`
  return `cover-${template}${scaleSuffix}-${width}x${height}.${extension}`
}
