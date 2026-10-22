import { COVER_IMAGE_FORMATS, type CoverImageFormat } from '@/lib/cover-generator/constants'

export const COVER_JPEG_QUALITY = 90
export const COVER_AVIF_QUALITY = 50

export function isCoverImageFormat(value: string): value is CoverImageFormat {
  return (COVER_IMAGE_FORMATS as readonly string[]).includes(value)
}

export function getCoverImageMimeType(format: CoverImageFormat): string {
  switch (format) {
    case 'jpeg':
      return 'image/jpeg'
    case 'avif':
      return 'image/avif'
    default:
      return 'image/png'
  }
}

export function getCoverImageExtension(format: CoverImageFormat): string {
  switch (format) {
    case 'jpeg':
      return 'jpg'
    case 'avif':
      return 'avif'
    default:
      return 'png'
  }
}

export function getCoverCanvasEncodeQuality(
  format: CoverImageFormat,
): number | undefined {
  switch (format) {
    case 'jpeg':
      return 0.92
    case 'avif':
      return 0.85
    default:
      return undefined
  }
}
