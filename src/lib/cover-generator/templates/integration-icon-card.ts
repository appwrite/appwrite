import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import {
  COVER_HERO_SCREENSHOT_FRAME,
  getCoverScreenshotGlassColors,
} from '@/lib/cover-generator/cover-screenshot-frame'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

export const INTEGRATION_ICON_DEFAULT_SIZE = 120
export const INTEGRATION_ICON_CARD_PADDING = 28
export const INTEGRATION_ICON_CARD_RADIUS = 32
const CARD_BORDER_WIDTH = COVER_HERO_SCREENSHOT_FRAME.borderWidth
const PLACEHOLDER_RADIUS = 14

export function getIntegrationIconCardSize(iconSize: number): number {
  return INTEGRATION_ICON_CARD_PADDING * 2 + iconSize
}

export function buildIntegrationIconCardSvg(options: {
  x: number
  y: number
  iconSize: number
  iconHref: string | null
  themeId: CoverThemeId
}): string {
  const { x, y, iconSize, iconHref, themeId } = options
  const cardSize = getIntegrationIconCardSize(iconSize)
  const glass = getCoverScreenshotGlassColors(themeId)
  const brand = getCoverBrandThemeForSvgExport(themeId)

  return `
    <g transform="translate(${x} ${y})">
      <rect
        width="${cardSize}"
        height="${cardSize}"
        rx="${INTEGRATION_ICON_CARD_RADIUS}"
        ry="${INTEGRATION_ICON_CARD_RADIUS}"
        fill="${glass.shellFill}"
        stroke="${glass.shellBorder}"
        stroke-width="${CARD_BORDER_WIDTH}"
      />
      ${
        iconHref
          ? `<image href="${iconHref}" x="${INTEGRATION_ICON_CARD_PADDING}" y="${INTEGRATION_ICON_CARD_PADDING}" width="${iconSize}" height="${iconSize}" />`
          : `<rect x="${INTEGRATION_ICON_CARD_PADDING}" y="${INTEGRATION_ICON_CARD_PADDING}" width="${iconSize}" height="${iconSize}" rx="${PLACEHOLDER_RADIUS}" fill="${brand.border}" opacity="0.35" />`
      }
    </g>
  `
}
