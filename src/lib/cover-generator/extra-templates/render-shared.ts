import sharp from 'sharp'
import {
  loadCoverImageBuffer,
  prepareCoverIconDataUri,
} from '@/lib/cover-generator/brand-background'
import {
  getCoverBrandThemeForSvgExport,
  type CoverBrandTheme,
} from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  type CoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import { escapeXml, formatCoverEyebrow, COVER_EYEBROW_LETTER_SPACING } from '@/lib/cover-generator/text-utils'
import type { CoverThemeFamily, CoverThemeId } from '@/lib/cover-generator/themes'
import { getCoverTheme } from '@/lib/cover-generator/themes'

export const EXTRA_COVER_CONTENT_X = 96
export const EXTRA_COVER_CONTENT_WIDTH = COVER_WIDTH - EXTRA_COVER_CONTENT_X * 2

/** Font stacks matching the export style block (cover-export-font-styles). */
export const EXTRA_COVER_TITLE_FONT = `'Aeonik Pro', Arial, Helvetica, sans-serif`
export const EXTRA_COVER_BODY_FONT = `'Inter', Arial, Helvetica, sans-serif`
export const EXTRA_COVER_MONO_FONT = `ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace`

export type ExtraCoverBrand = CoverBrandTheme

export function getExtraCoverBrand(themeId: CoverThemeId): ExtraCoverBrand {
  return getCoverBrandThemeForSvgExport(themeId)
}

/** Rough width estimate for centered/pill layouts (average glyph advance). */
export function estimateCoverTextWidth(
  text: string,
  fontSize: number,
  weight: 'regular' | 'semibold' | 'bold' = 'regular',
): number {
  const ratio = weight === 'bold' ? 0.6 : weight === 'semibold' ? 0.58 : 0.54
  return text.length * fontSize * ratio
}

export function renderExtraEyebrow(options: {
  eyebrow: string | undefined
  x: number
  layoutY: number
  brand: ExtraCoverBrand
  fontSize?: number
  anchor?: 'start' | 'middle'
}): string {
  const eyebrowText = formatCoverEyebrow(options.eyebrow)
  if (!eyebrowText) return ''
  const fontSize = options.fontSize ?? 18
  const anchor = options.anchor ?? 'start'

  return `<text class="cover-eyebrow" fill="${options.brand.mutedForeground}" font-size="${fontSize}" font-weight="600" letter-spacing="${COVER_EYEBROW_LETTER_SPACING}" x="${options.x}" y="${coverSvgTextBaseline(options.layoutY, fontSize)}" text-anchor="${anchor}">${escapeXml(eyebrowText)}<tspan fill="${options.brand.brandCta}">_</tspan></text>`
}

/** Wrapped title lines with the brand pink underscore on the last line. */
export function renderExtraTitleLines(options: {
  lines: string[]
  x: number
  startLayoutY: number
  fontSize: number
  brand: ExtraCoverBrand
  anchor?: 'start' | 'middle'
  lineGap?: number
  fill?: string
}): string {
  const anchor = options.anchor ?? 'start'
  const lineStep = options.fontSize + (options.lineGap ?? 8)
  const fill = options.fill ?? options.brand.foreground

  return options.lines
    .map((line, index) => {
      const layoutY = options.startLayoutY + index * lineStep
      const underscore =
        index === options.lines.length - 1
          ? `<tspan fill="${options.brand.brandCta}">_</tspan>`
          : ''
      return `<text class="cover-title" fill="${fill}" font-size="${options.fontSize}" x="${options.x}" y="${coverSvgTextBaseline(layoutY, options.fontSize)}" text-anchor="${anchor}">${escapeXml(line)}${underscore}</text>`
    })
    .join('')
}

export function renderExtraBodyLines(options: {
  lines: string[]
  x: number
  startLayoutY: number
  fontSize: number
  brand: ExtraCoverBrand
  anchor?: 'start' | 'middle'
  lineGap?: number
  fill?: string
}): string {
  const anchor = options.anchor ?? 'start'
  const lineStep = options.fontSize + (options.lineGap ?? 8)
  const fill = options.fill ?? options.brand.mutedForeground

  return options.lines
    .map((line, index) => {
      const layoutY = options.startLayoutY + index * lineStep
      return `<text class="cover-body" fill="${fill}" font-size="${options.fontSize}" x="${options.x}" y="${coverSvgTextBaseline(layoutY, options.fontSize)}" text-anchor="${anchor}">${escapeXml(line)}</text>`
    })
    .join('')
}

export function buildExtraGlassCardSvg(options: {
  x: number
  y: number
  width: number
  height: number
  radius: number
  glass: CoverScreenshotGlassColors
  borderWidth?: number
}): string {
  return `<rect x="${options.x}" y="${options.y}" width="${options.width}" height="${options.height}" rx="${options.radius}" ry="${options.radius}" fill="${options.glass.shellFill}" stroke="${options.glass.shellBorder}" stroke-width="${options.borderWidth ?? 2}" />`
}

export type ExtraCoverPillOptions = {
  x: number
  y: number
  label: string
  fontSize: number
  brand: ExtraCoverBrand
  anchor?: 'start' | 'middle'
  /** Pill visual style: tinted brand pill, solid brand fill, or neutral muted pill. */
  tone?: 'brand-outline' | 'brand-solid' | 'muted'
  paddingX?: number
  height?: number
  uppercase?: boolean
  fontWeight?: number
}

/** Rounded pill with centered label. Returns the SVG fragment. */
export function buildExtraPillSvg(options: ExtraCoverPillOptions): string {
  const label = options.uppercase ? options.label.toUpperCase() : options.label
  const fontWeight = options.fontWeight ?? 600
  const paddingX = options.paddingX ?? 24
  const height = options.height ?? Math.round(options.fontSize * 2.2)
  const width = Math.max(
    Math.round(
      estimateCoverTextWidth(label, options.fontSize, fontWeight >= 600 ? 'semibold' : 'regular') +
        paddingX * 2 +
        (options.uppercase ? label.length * options.fontSize * 0.18 : 0),
    ),
    height,
  )
  const x = options.anchor === 'middle' ? options.x - width / 2 : options.x

  const colors = {
    'brand-outline': {
      fill: options.brand.brandCta,
      fillOpacity: 0.1,
      stroke: options.brand.brandCta,
      strokeOpacity: 0.4,
      text: options.brand.brandCta,
    },
    'brand-solid': {
      fill: options.brand.brandCta,
      fillOpacity: 1,
      stroke: 'none',
      strokeOpacity: 0,
      text: '#ffffff',
    },
    muted: {
      fill: options.brand.muted,
      fillOpacity: 0.6,
      stroke: options.brand.border,
      strokeOpacity: 1,
      text: options.brand.mutedForeground,
    },
  }[options.tone ?? 'brand-outline']

  const stroke =
    colors.stroke === 'none'
      ? ''
      : ` stroke="${colors.stroke}" stroke-opacity="${colors.strokeOpacity}" stroke-width="1.5"`
  const letterSpacing = options.uppercase ? ' letter-spacing="0.12em"' : ''

  return `
    <rect x="${x}" y="${options.y}" width="${width}" height="${height}" rx="${height / 2}" ry="${height / 2}" fill="${colors.fill}" fill-opacity="${colors.fillOpacity}"${stroke} />
    <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${colors.text}" font-size="${options.fontSize}" font-weight="${fontWeight}"${letterSpacing} x="${x + width / 2}" y="${coverSvgTextBaseline(options.y + (height - options.fontSize) / 2, options.fontSize)}" text-anchor="middle">${escapeXml(label)}</text>
  `
}

export function getCoverNameInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '?'
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : ''
  return `${first}${last}`.toUpperCase() || '?'
}

/** Circle avatar from an image href, or a glass initials placeholder matching the browser frame. */
export function buildExtraAvatarSvg(options: {
  href: string | null
  name: string
  cx: number
  cy: number
  radius: number
  brand: ExtraCoverBrand
  glass: CoverScreenshotGlassColors
  clipId: string
  ring?: boolean
  /** Stronger initials disc so the circle reads at the byline height. */
  solidPlaceholder?: boolean
}): string {
  const { cx, cy, radius, brand, glass, clipId } = options
  const ring = options.ring
    ? `<circle cx="${cx}" cy="${cy}" r="${radius + 5}" fill="none" stroke="url(#cover-title-gradient)" stroke-width="3" />`
    : ''

  if (options.href) {
    return `
      ${ring}
      <clipPath id="${clipId}"><circle cx="${cx}" cy="${cy}" r="${radius}" /></clipPath>
      <image href="${options.href}" x="${cx - radius}" y="${cy - radius}" width="${radius * 2}" height="${radius * 2}" clip-path="url(#${clipId})" preserveAspectRatio="xMidYMid slice" />
    `
  }

  const initials = getCoverNameInitials(options.name)
  const fontSize = Math.round(radius * (options.solidPlaceholder ? 0.72 : 0.82))
  const disc = options.solidPlaceholder
    ? `<circle cx="${cx}" cy="${cy}" r="${radius}" fill="${brand.foreground}" fill-opacity="0.1" stroke="${brand.foreground}" stroke-opacity="0.28" stroke-width="${COVER_HERO_SCREENSHOT_FRAME.borderWidth}" />`
    : `<circle cx="${cx}" cy="${cy}" r="${radius}" fill="${glass.shellFill}" stroke="${glass.shellBorder}" stroke-width="${COVER_HERO_SCREENSHOT_FRAME.borderWidth}" />`
  return `
    ${ring}
    ${disc}
    <text font-family="${EXTRA_COVER_BODY_FONT}" fill="${brand.mutedForeground}" font-size="${fontSize}" font-weight="600" x="${cx}" y="${coverSvgTextBaseline(cy - fontSize / 2, fontSize)}" text-anchor="middle">${escapeXml(initials)}</text>
  `
}

/** Rasterize an avatar/icon source to a square PNG data URI (no padding). */
export async function prepareExtraCoverImage(
  source: string | undefined,
  size: number,
  themeId: CoverThemeId,
  options?: { insetRatio?: number; fit?: 'contain' | 'cover' },
): Promise<string | null> {
  if (options?.fit === 'cover') {
    const buffer = await loadCoverImageBuffer(source)
    if (!buffer || size <= 0) return null
    const png = await sharp(buffer)
      .resize(size, size, { fit: 'cover', position: 'centre' })
      .png()
      .toBuffer()
    return `data:image/png;base64,${png.toString('base64')}`
  }

  const theme = getCoverTheme(themeId)
  const family: CoverThemeFamily = theme.family
  return prepareCoverIconDataUri(source, size, {
    themeFamily: family,
    themeId,
    insetRatio: options?.insetRatio ?? 0,
  })
}

export { COVER_HEIGHT as EXTRA_COVER_HEIGHT, COVER_WIDTH as EXTRA_COVER_WIDTH }
