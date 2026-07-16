import sharp from 'sharp'
import { readCoverPublicAssetDataUri } from '@/lib/cover-generator/public-assets'
import { buildCoverExportFontStyleBlock } from '@/lib/cover-generator/cover-export-font-styles'
export { buildCoverExportFontStyleBlock, buildCoverFontStyleBlock } from '@/lib/cover-generator/cover-export-font-styles'
import {
  COVER_HEIGHT,
  COVER_WIDTH,
  type CoverTheme,
  type CoverTemplateId,
} from '@/lib/cover-generator/constants'
import { buildCoverTitleGradientSvgDef } from '@/lib/cover-generator/cover-title-gradient'
import type { CoverTitleGradientBounds } from '@/lib/cover-generator/cover-title-gradient'
import {
  buildCoverBrandBackgroundSvgLayers,
  type CoverBackgroundContext,
} from '@/lib/cover-generator/cover-soft-lights'
import {
  getCoverContentLayoutTransform,
  type CoverContentLayoutAnchor,
} from '@/lib/cover-generator/cover-layout-scale'
import {
  getCoverBrandThemeForSvgExport,
  type CoverBrandTheme,
} from '@/lib/cover-generator/brand-theme'
import {
  getCoverIconRasterTint,
  getCoverLucideIconStrokeColorFromOptions,
} from '@/lib/cover-generator/cover-icon-tone'
import { isCoverLucideIconValue, parseCoverLucideIconName } from '@/lib/cover-generator/lucide-icon-utils'
import { loadCoverLucideIconSvgBuffer } from '@/lib/cover-generator/lucide-icon-render'
import type { CoverThemeFamily, CoverThemeId } from '@/lib/cover-generator/themes'

export function buildCoverBrandBackgroundParts(
  theme: CoverTheme,
  width = COVER_WIDTH,
  height = COVER_HEIGHT,
  titleGradientBounds?: CoverTitleGradientBounds,
  templateId?: CoverTemplateId,
): { defs: string; layers: string } {
  const context: CoverBackgroundContext | undefined = templateId
    ? { templateId }
    : undefined
  const { defs: backgroundDefs, layers } = buildCoverBrandBackgroundSvgLayers(
    theme,
    width,
    height,
    context,
  )

  const defs = `
      ${backgroundDefs}
      ${buildCoverTitleGradientSvgDef(theme, titleGradientBounds)}
  `

  return { defs, layers }
}

export function buildCoverBrandBackgroundSvg(
  theme: CoverTheme,
  width = COVER_WIDTH,
  height = COVER_HEIGHT,
): string {
  const { defs, layers } = buildCoverBrandBackgroundParts(theme, width, height)
  return `
    <defs>${defs}</defs>
    ${layers}
  `
}

export function buildCoverSvgShell(params: {
  theme: CoverTheme
  width?: number
  height?: number
  fontFaceCss?: string
  titleGradientBounds?: CoverTitleGradientBounds
  templateId?: CoverTemplateId
  content: string
  contentAnchor?: CoverContentLayoutAnchor
}): string {
  const outputWidth = params.width ?? COVER_WIDTH
  const outputHeight = params.height ?? COVER_HEIGHT
  const layoutTransform = getCoverContentLayoutTransform(
    outputWidth,
    outputHeight,
    params.contentAnchor,
  )
  // Gradient text lives inside the scaled content group; userSpaceOnUse coords must
  // stay in artboard space (1200×630), not output canvas space.
  const titleGradientBounds = params.titleGradientBounds
  const { defs, layers } = buildCoverBrandBackgroundParts(
    params.theme,
    outputWidth,
    outputHeight,
    titleGradientBounds,
    params.templateId,
  )
  const { scale, translateX, translateY } = layoutTransform

  return `
    <svg width="${outputWidth}" height="${outputHeight}" viewBox="0 0 ${outputWidth} ${outputHeight}" xmlns="http://www.w3.org/2000/svg">
      ${buildCoverExportFontStyleBlock(params.fontFaceCss ?? '')}
      <defs>${defs}</defs>
      ${layers}
      <g transform="translate(${translateX} ${translateY}) scale(${scale})">
        ${params.content}
      </g>
    </svg>
  `
}

export function getTitleFill(
  brand: CoverBrandTheme,
  gradientTitle: boolean,
): string {
  return gradientTitle ? 'url(#cover-title-gradient)' : brand.foreground
}

export async function resolveCoverImageHref(
  source: string | undefined,
): Promise<string | null> {
  const value = source?.trim()
  if (!value) return null
  if (value.startsWith('data:')) return value
  if (value.startsWith('/')) {
    const ext = value.split('.').pop()?.toLowerCase()
    const mime =
      ext === 'svg'
        ? 'image/svg+xml'
        : ext === 'png'
          ? 'image/png'
          : ext === 'jpg' || ext === 'jpeg'
            ? 'image/jpeg'
            : ext === 'webp'
              ? 'image/webp'
              : ext === 'avif'
                ? 'image/avif'
                : 'application/octet-stream'
    return readCoverPublicAssetDataUri(value, mime)
  }
  if (/^https?:\/\//i.test(value)) return value
  return null
}

export async function loadCoverImageBuffer(
  source: string | undefined,
  options: { lucideStroke?: string } = {},
): Promise<Buffer | null> {
  if (isCoverLucideIconValue(source)) {
    const iconName = parseCoverLucideIconName(source)
    if (!iconName || !options.lucideStroke) return null
    return loadCoverLucideIconSvgBuffer(iconName, options.lucideStroke)
  }

  const href = await resolveCoverImageHref(source)
  if (!href) return null
  if (href.startsWith('data:')) {
    const match = href.match(/^data:[^;]+;base64,(.+)$/)
    if (!match?.[1]) return null
    return Buffer.from(match[1], 'base64')
  }
  try {
    const response = await fetch(href)
    if (!response.ok) return null
    return Buffer.from(await response.arrayBuffer())
  } catch {
    return null
  }
}

/** Breathing room so icon artwork is not flush against the export slot edge. */
export const COVER_ICON_SAFE_INSET_RATIO = 0.12

export type PrepareCoverIconOptions = {
  insetRatio?: number
  themeFamily?: CoverThemeFamily
  themeId?: CoverThemeId
  /** Left-align artwork (vertically centered) for templates that share an x anchor with text. */
  contentAlign?: 'center' | 'left'
}

export function getCoverIconSafeInset(
  size: number,
  insetRatio: number = COVER_ICON_SAFE_INSET_RATIO,
): number {
  return Math.max(1, Math.round(size * insetRatio))
}

/** Rasterize icons/logos to a padded square PNG so viewBox quirks and slot clipping are avoided. */
export async function prepareCoverIconDataUri(
  source: string | undefined,
  size: number,
  options: PrepareCoverIconOptions = {},
): Promise<string | null> {
  const lucideStroke = isCoverLucideIconValue(source)
    ? getCoverLucideIconStrokeColorFromOptions(options)
    : undefined
  const buffer = await loadCoverImageBuffer(source, { lucideStroke })
  if (!buffer || size <= 0) return null

  const insetRatio = options.insetRatio ?? COVER_ICON_SAFE_INSET_RATIO
  const inset = getCoverIconSafeInset(size, insetRatio)
  const contentAlign = options.contentAlign ?? 'center'
  const inner =
    contentAlign === 'left'
      ? Math.max(1, size - inset)
      : Math.max(1, size - inset * 2)
  const tint = getCoverIconRasterTint(source, options.themeFamily)

  // Left-aligned icons stay flush on the left (for lockups next to text) but are
  // vertically centered so they sit optically with the title, not top-biased.
  const leftTopInset = contentAlign === 'left' ? Math.floor(inset / 2) : inset
  const leftBottomInset = contentAlign === 'left' ? Math.ceil(inset / 2) : inset

  let pipeline = sharp(buffer, {
    density: Math.max(96, Math.ceil(inner * 2)),
  }).resize(inner, inner, {
    fit: 'contain',
    // 'west' = left + vertically centered (sharp rejects "left centre")
    position: contentAlign === 'left' ? 'west' : 'centre',
    background: { r: 0, g: 0, b: 0, alpha: 0 },
  })

  if (tint) {
    const resized = pipeline.ensureAlpha()
    const { width, height } = await resized.clone().metadata()
    if (!width || !height) return null

    const alpha = await resized.clone().extractChannel('alpha').toBuffer()
    pipeline = sharp({
      create: {
        width,
        height,
        channels: 3,
        background: tint,
      },
    }).joinChannel(alpha)
  }

  const png = await pipeline
    .extend(
      contentAlign === 'left'
        ? {
            top: leftTopInset,
            bottom: leftBottomInset,
            left: 0,
            right: inset,
            background: { r: 0, g: 0, b: 0, alpha: 0 },
          }
        : {
            top: inset,
            bottom: inset,
            left: inset,
            right: inset,
            background: { r: 0, g: 0, b: 0, alpha: 0 },
          },
    )
    .png()
    .toBuffer()

  return `data:image/png;base64,${png.toString('base64')}`
}
