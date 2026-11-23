import sharp from 'sharp'
import { loadCoverImageBuffer, resolveCoverImageHref } from '@/lib/cover-generator/brand-background'
import { getCoverScreenshotAngledInnerDimensions } from '@/lib/cover-generator/cover-screenshot-angled-frame'
import { getScreenshotCropRect } from '@/lib/cover-generator/screenshot-crop'
import type { CoverRenderData, CoverScreenshotFields } from '@/lib/cover-generator/types'

export type PreparedCoverData = {
  logoLeft: string | null
  logoRight: string | null
  icon: string | null
  screenshot: string | null
}

async function bufferToPngDataUri(buffer: Buffer): Promise<string> {
  return `data:image/png;base64,${buffer.toString('base64')}`
}

async function prepareScreenshotDataUri(
  data: CoverScreenshotFields,
  outputWidth: number,
  outputHeight: number,
): Promise<string | null> {
  if (!data.screenshot?.trim()) return null

  const input = await loadCoverImageBuffer(data.screenshot)
  if (!input) return null

  const metadata = await sharp(input).metadata()
  const naturalWidth = metadata.width ?? 0
  const naturalHeight = metadata.height ?? 0
  if (!naturalWidth || !naturalHeight) return null

  const crop = getScreenshotCropRect(
    naturalWidth,
    naturalHeight,
    outputWidth,
    outputHeight,
    data,
  )

  const buffer = await sharp(input)
    .extract({
      left: crop.left,
      top: crop.top,
      width: crop.width,
      height: crop.height,
    })
    .resize(outputWidth, outputHeight, { fit: 'cover' })
    .png()
    .toBuffer()

  return bufferToPngDataUri(buffer)
}

export async function prepareCoverRenderData(
  data: CoverRenderData,
): Promise<PreparedCoverData> {
  switch (data.template) {
    case 'integration': {
      const [logoLeft, logoRight] = await Promise.all([
        resolveCoverImageHref(data.logoLeft),
        resolveCoverImageHref(data.logoRight),
      ])
      return { logoLeft, logoRight, icon: null, screenshot: null }
    }
    case 'showcase-icon': {
      const icon = await resolveCoverImageHref(data.icon)
      return { logoLeft: null, logoRight: null, icon, screenshot: null }
    }
    case 'screenshot-angled': {
      const { width, height } = getCoverScreenshotAngledInnerDimensions(data)
      const screenshot = await prepareScreenshotDataUri(data, width, height)
      return { logoLeft: null, logoRight: null, icon: null, screenshot }
    }
    default:
      return { logoLeft: null, logoRight: null, icon: null, screenshot: null }
  }
}
