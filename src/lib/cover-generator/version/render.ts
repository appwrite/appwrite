import { getTitleFill } from '@/lib/cover-generator/brand-background'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import type { CoverTitleGradientBounds } from '@/lib/cover-generator/cover-title-gradient'
import { coverSvgTextBaseline } from '@/lib/cover-generator/cover-svg-text'
import {
  escapeXml,
  formatCoverEyebrow,
  stripCoverTitleSuffix,
  wrapTextLines,
  clampNumber,
} from '@/lib/cover-generator/text-utils'
import type {
  CoverVersionNumberFields,
  CoverVersionTitleFields,
} from '@/lib/cover-generator/version/constants'
import type { CoverTheme } from '@/lib/cover-generator/constants'

const COVER_CONTENT_X = 64
const COVER_EYEBROW_FONT_SIZE = 18
const COVER_EYEBROW_VERSION_GAP = 20
const COVER_VERSION_TITLE_GAP = 36
const COVER_TITLE_MAX_WIDTH = COVER_WIDTH - COVER_CONTENT_X * 2
const COVER_BOTTOM_PADDING = 8

export type VersionTemplateSvgResult = {
  content: string
  titleGradientBounds?: CoverTitleGradientBounds
}

/** Hero version sizing for the number-only layout. */
export function getVersionHeroFontSize(version: string): number {
  const length = version.trim().length
  if (length <= 3) return 480
  if (length <= 5) return 420
  if (length <= 7) return 350
  if (length <= 10) return 280
  return 210
}

/** Large hero sizing when a title sits under the version. */
export function getVersionTitleHeroFontSize(version: string): number {
  const length = version.trim().length
  if (length <= 3) return 420
  if (length <= 5) return 380
  if (length <= 7) return 310
  if (length <= 10) return 250
  return 190
}

function getVersionTitleFontSize(title: string): number {
  const text = stripCoverTitleSuffix(title)
  const length = Math.max(text.length, 1)
  const computed = Math.floor(COVER_TITLE_MAX_WIDTH / (length * 0.62))
  return clampNumber(computed, 28, 46)
}

function buildVersionGradientBounds(
  centerX: number,
  y: number,
  version: string,
  fontSize: number,
): CoverTitleGradientBounds {
  const width = Math.min(
    COVER_WIDTH - COVER_CONTENT_X * 2,
    Math.max(version.length * fontSize * 0.62, 120),
  )
  return {
    x: centerX - width / 2,
    y,
    width,
    height: fontSize,
  }
}

function buildVersionStack(params: {
  data: CoverVersionNumberFields & { title?: string }
  theme: CoverTheme
  includeTitle: boolean
}): VersionTemplateSvgResult {
  const brand = getCoverBrandThemeForSvgExport(params.theme)
  const centerX = COVER_WIDTH / 2
  const version = params.data.version.trim() || '0.0.0'
  const versionFontSize = params.includeTitle
    ? getVersionTitleHeroFontSize(version)
    : getVersionHeroFontSize(version)
  const versionFill = getTitleFill(brand, true)
  const eyebrowText = formatCoverEyebrow(params.data.eyebrow)

  const titleLine = params.includeTitle
    ? (wrapTextLines(stripCoverTitleSuffix(params.data.title ?? ''), 120, 1)[0] ??
      stripCoverTitleSuffix(params.data.title ?? ''))
    : ''
  const titleFontSize = params.includeTitle ? getVersionTitleFontSize(titleLine) : 0
  const titleLineStep = titleFontSize + 8

  const eyebrowBlockHeight = eyebrowText
    ? COVER_EYEBROW_FONT_SIZE + COVER_EYEBROW_VERSION_GAP
    : 0
  const versionBlockHeight = versionFontSize
  const titleBlockHeight = params.includeTitle && titleLine ? titleLineStep : 0
  const titleGap = titleBlockHeight > 0 ? COVER_VERSION_TITLE_GAP : 0

  const totalHeight =
    eyebrowBlockHeight +
    versionBlockHeight +
    titleGap +
    titleBlockHeight +
    COVER_BOTTOM_PADDING

  let cursorY = Math.round((COVER_HEIGHT - totalHeight) / 2)
  const parts: string[] = []

  if (eyebrowText) {
    parts.push(
      `<text class="cover-eyebrow" fill="${brand.mutedForeground}" font-size="${COVER_EYEBROW_FONT_SIZE}" font-weight="600" letter-spacing="0.25em" x="${centerX}" y="${coverSvgTextBaseline(cursorY, COVER_EYEBROW_FONT_SIZE)}" text-anchor="middle">${escapeXml(eyebrowText)}<tspan fill="${brand.brandCta}">_</tspan></text>`,
    )
    cursorY += eyebrowBlockHeight
  }

  const versionLayoutY = cursorY
  parts.push(
    `<text class="cover-title" fill="${versionFill}" font-size="${versionFontSize}" x="${centerX}" y="${coverSvgTextBaseline(versionLayoutY, versionFontSize)}" text-anchor="middle">${escapeXml(version)}</text>`,
  )
  cursorY += versionBlockHeight + titleGap

  if (params.includeTitle && titleLine) {
    parts.push(
      `<text class="cover-title" fill="${brand.foreground}" font-size="${titleFontSize}" x="${centerX}" y="${coverSvgTextBaseline(cursorY, titleFontSize)}" text-anchor="middle">${escapeXml(titleLine)}<tspan fill="${brand.brandCta}">_</tspan></text>`,
    )
  }

  return {
    content: parts.join('\n'),
    titleGradientBounds: buildVersionGradientBounds(
      centerX,
      versionLayoutY,
      version,
      versionFontSize,
    ),
  }
}

export function renderVersionNumberTemplateSvg(
  data: CoverVersionNumberFields,
  theme: CoverTheme,
): VersionTemplateSvgResult {
  return buildVersionStack({ data, theme, includeTitle: false })
}

export function renderVersionTitleTemplateSvg(
  data: CoverVersionTitleFields,
  theme: CoverTheme,
): VersionTemplateSvgResult {
  return buildVersionStack({ data, theme, includeTitle: true })
}
