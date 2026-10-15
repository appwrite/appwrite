import sharp from 'sharp'
import {
  COVER_AVIF_QUALITY,
  COVER_JPEG_QUALITY,
} from '@/lib/cover-generator/cover-image-format'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'

export async function applyCoverImageFormat(
  pipeline: sharp.Sharp,
  format: CoverImageFormat,
): Promise<Buffer> {
  switch (format) {
    case 'jpeg':
      return pipeline.jpeg({ quality: COVER_JPEG_QUALITY }).toBuffer()
    case 'avif':
      return pipeline.avif({ quality: COVER_AVIF_QUALITY }).toBuffer()
    default:
      return pipeline.png().toBuffer()
  }
}

export async function encodeCoverImageBuffer(
  input: Buffer,
  format: CoverImageFormat,
): Promise<Buffer> {
  if (format === 'png') {
    return sharp(input).png().toBuffer()
  }
  return applyCoverImageFormat(sharp(input), format)
}
