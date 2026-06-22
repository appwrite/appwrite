import sharp from 'sharp'
import {
  buildCoverSvgShell,
  loadCoverImageBuffer,
} from '@/lib/cover-generator/brand-background'
import { encodeCoverImageBuffer } from '@/lib/cover-generator/encode-cover-image-sharp'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  buildCoverScreenshotChromeSvg,
  buildCoverScreenshotClipSvg,
  buildCoverScreenshotGlassFrameSvg,
  COVER_HERO_SCREENSHOT_FRAME,
  COVER_SCREENSHOT_TITLE,
  getCoverScreenshotGlassColors,
  getCoverScreenshotSceneLayout,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { COVER_WIDTH } from '@/lib/cover-generator/constants'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  getCoverContentLayoutTransform,
  transformCoverArtboardRect,
} from '@/lib/cover-generator/cover-layout-scale'
import { getCoverFontFaceCss } from '@/lib/cover-generator/font-embed'
import { getScreenshotCropRect } from '@/lib/cover-generator/screenshot-crop'
import { escapeXml } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData, CoverScreenshotData } from '@/lib/cover-generator/types'
import type { CoverTheme } from '@/lib/cover-generator/constants'

async function cropScreenshotToFrame(
  source: string,
  data: CoverScreenshotData,
  outputWidth: number,
  outputHeight: number,
): Promise<Buffer | null> {
  const input = await loadCoverImageBuffer(source)
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

  return sharp(input)
    .extract({
      left: crop.left,
      top: crop.top,
      width: crop.width,
      height: crop.height,
    })
    .resize(outputWidth, outputHeight, { fit: 'fill' })
    .png()
    .toBuffer()
}

function buildScreenshotOverlayContent(
  data: Extract<CoverRenderData, { template: 'screenshot' }>,
  theme: CoverTheme,
): string {
  const brand = getCoverBrandThemeForSvgExport(theme)
  const glass = getCoverScreenshotGlassColors(theme)
  const { titleLines, layout, titleY, subtitleY } = getCoverScreenshotSceneLayout(data)
  const { fontSize, lineHeight, subtitleFontSize } = COVER_SCREENSHOT_TITLE

  return `
    ${buildCoverScreenshotGlassFrameSvg(layout, glass)}
    ${buildCoverScreenshotChromeSvg(layout, glass)}
    ${
      titleLines.length
        ? titleLines
            .map(
              (line, index) =>
                `<text text-anchor="middle" class="cover-title" fill="${brand.foreground}" font-size="${fontSize}" x="${COVER_WIDTH / 2}" y="${coverSvgTextBaseline(titleY + index * lineHeight, fontSize)}">${escapeXml(line)}${
                  index === titleLines.length - 1
                    ? `<tspan fill="${brand.brandCta}">_</tspan>`
                    : ''
                }</text>`,
            )
            .join('')
        : ''
    }
    ${
      data.subtitle
        ? `<text text-anchor="middle" class="cover-body" fill="${brand.mutedForeground}" font-size="${subtitleFontSize}" x="${COVER_WIDTH / 2}" y="${coverSvgTextBaseline(subtitleY, subtitleFontSize)}">${escapeXml(data.subtitle)}</text>`
        : ''
    }
  `
}

export async function renderScreenshotCoverPng(
  data: Extract<CoverRenderData, { template: 'screenshot' }>,
): Promise<Uint8Array> {
  const brand = getCoverBrandThemeForSvgExport(data.theme)
  const { layout } = getCoverScreenshotSceneLayout(data)
  const layoutTransform = getCoverContentLayoutTransform(
    data.width,
    data.height,
    'bottom',
  )
  const { screenshot: shotRect, innerRadius } = layout
  const outputShotRect = transformCoverArtboardRect(shotRect, layoutTransform)
  const outputInnerRadius = Math.max(1, Math.round(innerRadius * layoutTransform.scale))

  const screenshotBuffer = data.screenshot
    ? await cropScreenshotToFrame(
        data.screenshot,
        data,
        layout.screenshot.width,
        layout.screenshot.height,
      )
    : null

  const fontFaceCss = await getCoverFontFaceCss()

  const frameSvg = buildCoverSvgShell({
    theme: data.theme,
    width: data.width,
    height: data.height,
    fontFaceCss,
    templateId: data.template,
    contentAnchor: 'bottom',
    content: buildScreenshotOverlayContent(data, data.theme),
  })

  const base = sharp(Buffer.from(frameSvg))
  const { x: left, y: top, width: outputWidth, height: outputHeight } = outputShotRect

  if (!screenshotBuffer) {
    const placeholderSvg = `
      <svg width="${outputWidth}" height="${outputHeight}" xmlns="http://www.w3.org/2000/svg">
        <rect width="100%" height="100%" fill="${brand.background}" opacity="0.55" />
        <text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle" fill="${brand.mutedForeground}" font-size="22" font-family="sans-serif">Screenshot preview</text>
      </svg>
    `

    const roundedPlaceholder = await sharp(Buffer.from(placeholderSvg))
      .png()
      .composite([
        {
          input: Buffer.from(
            buildCoverScreenshotClipSvg(outputWidth, outputHeight, outputInnerRadius),
          ),
          blend: 'dest-in',
        },
      ])
      .png()
      .toBuffer()

    const png = await base
      .composite([
        {
          input: roundedPlaceholder,
          left,
          top,
        },
      ])
      .png()
      .toBuffer()

    if (data.format === 'png') return png
    return encodeCoverImageBuffer(png, data.format)
  }

  const scaledScreenshot = await sharp(screenshotBuffer)
    .resize(outputWidth, outputHeight, { fit: 'fill' })
    .ensureAlpha()
    .png()
    .toBuffer()

  const roundedScreenshot = await sharp(scaledScreenshot)
    .ensureAlpha()
    .composite([
      {
        input: Buffer.from(
          buildCoverScreenshotClipSvg(outputWidth, outputHeight, outputInnerRadius),
        ),
        blend: 'dest-in',
      },
    ])
    .png()
    .toBuffer()

  const screenshotWithOpacity = await applyScreenshotOpacity(
    roundedScreenshot,
    COVER_HERO_SCREENSHOT_FRAME.imageOpacity,
  )

  const png = await base
    .composite([
      {
        input: screenshotWithOpacity,
        left,
        top,
      },
    ])
    .png()
    .toBuffer()

  if (data.format === 'png') return png
  return encodeCoverImageBuffer(png, data.format)
}

async function applyScreenshotOpacity(
  buffer: Buffer,
  opacity: number,
): Promise<Buffer> {
  const { data, info } = await sharp(buffer)
    .ensureAlpha()
    .raw()
    .toBuffer({ resolveWithObject: true })

  for (let index = 3; index < data.length; index += 4) {
    data[index] = Math.round(data[index] * opacity)
  }

  return sharp(data, {
    raw: {
      width: info.width,
      height: info.height,
      channels: 4,
    },
  })
    .png()
    .toBuffer()
}
