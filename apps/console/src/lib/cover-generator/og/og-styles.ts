import {
  COVER_TITLE_GRADIENT_ANGLE_DEG,
  getCoverTitleGradientSvgStops,
} from '@/lib/cover-generator/cover-title-gradient'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

export const COVER_OG_FONT_FAMILY = 'Aeonik Pro'

export function getCoverOgTitleGradientBackground(themeId: CoverThemeId): string {
  const stops = getCoverTitleGradientSvgStops(themeId)
  const gradientStops = stops.map((stop) => `${stop.color} ${stop.offset}`).join(', ')
  return `linear-gradient(${COVER_TITLE_GRADIENT_ANGLE_DEG}deg, ${gradientStops})`
}

export function coverOgTitleGradientStyle(themeId: CoverThemeId) {
  return {
    backgroundImage: getCoverOgTitleGradientBackground(themeId),
    backgroundClip: 'text' as const,
    color: 'transparent' as const,
  }
}

export function coverOgTextStyle(
  size: number,
  color: string,
  extra?: Record<string, string | number>,
) {
  return {
    fontFamily: COVER_OG_FONT_FAMILY,
    fontSize: size,
    color,
    margin: 0,
    padding: 0,
    lineHeight: 1.2,
    ...extra,
  }
}
