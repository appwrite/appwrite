import sharp from 'sharp'
import {
  buildCoverExportFontStyleBlock,
  buildCoverSvgShell,
  loadCoverImageBuffer,
} from '@/lib/cover-generator/brand-background'
import { encodeCoverImageBuffer } from '@/lib/cover-generator/encode-cover-image-sharp'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  buildCoverScreenshotChromeSvg,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import {
  buildCoverScreenshotSideClipSvg,
  buildCoverScreenshotSideGlassFrameSvg,
  buildCoverScreenshotSideScreenshotBorderSvg,
  COVER_SCREENSHOT_SIDE_TITLE,
  getCoverScreenshotSideSceneLayout,
} from '@/lib/cover-generator/cover-screenshot-side-frame'
import {
  getCoverContentLayoutTransform,
  transformCoverArtboardRect,
} from '@/lib/cover-generator/cover-layout-scale'
import { getCoverFontFaceCss } from '@/lib/cover-generator/font-embed'
import { getScreenshotCropRect } from '@/lib/cover-generator/screenshot-crop'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml } from '@/lib/cover-generator/text-utils'
import type { CoverRenderData, CoverScreenshotData } from '@/lib/cover-generator/types'

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

function buildScreenshotSideTitleSvgContent(
  data: Extract<CoverRenderData, { template: 'screenshot-side' }>,
  titleLines: string[],
  titleX: number,
  titleY: number,
  subtitleY: number,
): string {
  const brand = getCoverBrandThemeForSvgExport(data.theme)
  const { fontSize, lineHeight, subtitleFontSize } = COVER_SCREENSHOT_SIDE_TITLE

  return `
    ${
      titleLines.length
        ? titleLines
            .map(
              (line, index) =>
                `<text text-anchor="start" class="cover-title" fill="${brand.foreground}" font-size="${fontSize}" x="${titleX}" y="${coverSvgTextBaseline(titleY + index * lineHeight, fontSize)}">${escapeXml(line)}${
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
        ? `<text text-anchor="start" class="cover-body" fill="${brand.mutedForeground}" font-size="${subtitleFontSize}" x="${titleX}" y="${coverSvgTextBaseline(subtitleY, subtitleFontSize)}">${escapeXml(data.subtitle)}</text>`
        : ''
    }
  `
}

function buildScreenshotSideFrameOverlayContent(
  data: Extract<CoverRenderData, { template: 'screenshot-side' }>,
): string {
  const brand = getCoverBrandThemeForSvgExport(data.theme)
  const glass = getCoverScreenshotGlassColors(data.theme)
  const { layout } = getCoverScreenshotSideSceneLayout(data)

  return `
    ${buildCoverScreenshotSideGlassFrameSvg(layout, glass)}
    ${buildCoverScreenshotChromeSvg(layout, glass)}
    ${buildCoverScreenshotSideScreenshotBorderSvg(layout, brand.border)}
  `
}

function buildScreenshotSideTitleOverlaySvg(
  data: Extract<CoverRenderData, { template: 'screenshot-side' }>,
  fontFaceCss: string,
): string {
  const { titleLines, titleX, titleY, subtitleY } =
    getCoverScreenshotSideSceneLayout(data)
  const layoutTransform = getCoverContentLayoutTransform(
    data.width,
    data.height,
    'right',
  )
  const { scale, translateX, translateY } = layoutTransform
  const content = buildScreenshotSideTitleSvgContent(
    data,
    titleLines,
    titleX,
    titleY,
    subtitleY,
  )

  return `
    <svg width="${data.width}" height="${data.height}" viewBox="0 0 ${data.width} ${data.height}" xmlns="http://www.w3.org/2000/svg">
      ${buildCoverExportFontStyleBlock(fontFaceCss)}
      <g transform="translate(${translateX} ${translateY}) scale(${scale})">
        ${content}
      </g>
    </svg>
  `
}

async function compositeScreenshotSideTitleLayer(
  baseBuffer: Buffer,
  data: Extract<CoverRenderData, { template: 'screenshot-side' }>,
  fontFaceCss: string,
): Promise<Buffer> {
  const titleSvg = buildScreenshotSideTitleOverlaySvg(data, fontFaceCss)
  const titleLayer = await sharp(Buffer.from(titleSvg)).png().toBuffer()

  return sharp(baseBuffer)
    .composite([
      {
        input: titleLayer,
        left: 0,
        top: 0,
      },
    ])
    .png()
    .toBuffer()
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

export async function renderScreenshotSideCoverPng(
  data: Extract<CoverRenderData, { template: 'screenshot-side' }>,
): Promise<Uint8Array> {
  const brand = getCoverBrandThemeForSvgExport(data.theme)
  const { layout } = getCoverScreenshotSideSceneLayout(data)
  const layoutTransform = getCoverContentLayoutTransform(
    data.width,
    data.height,
    'right',
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
    contentAnchor: 'right',
    content: buildScreenshotSideFrameOverlayContent(data),
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
            buildCoverScreenshotSideClipSvg(outputWidth, outputHeight, outputInnerRadius),
          ),
          blend: 'dest-in',
        },
      ])
      .png()
      .toBuffer()

    const withScreenshot = await base
      .composite([
        {
          input: roundedPlaceholder,
          left,
          top,
        },
      ])
      .png()
      .toBuffer()

    const png = await compositeScreenshotSideTitleLayer(withScreenshot, data, fontFaceCss)

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
          buildCoverScreenshotSideClipSvg(outputWidth, outputHeight, outputInnerRadius),
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

  const withScreenshot = await base
    .composite([
      {
        input: screenshotWithOpacity,
        left,
        top,
      },
    ])
    .png()
    .toBuffer()

  const png = await compositeScreenshotSideTitleLayer(withScreenshot, data, fontFaceCss)

  if (data.format === 'png') return png
  return encodeCoverImageBuffer(png, data.format)
}
