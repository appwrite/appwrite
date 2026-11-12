import type { CoverTitleGradientStop } from '@/lib/cover-generator/cover-title-gradient'
import { mixOklchHex } from '@/lib/cover-generator/cover-oklch-mix'
import {
  getCoverBrandThemeForSvgExport,
  getCoverTheme,
  type CoverThemeId,
} from '@/lib/cover-generator/themes'

/** Matches `.text-gradient-brand` in `src/styles.css`. */
export function getCoverTitleGradientSvgStopsForTheme(
  themeId: CoverThemeId,
): CoverTitleGradientStop[] {
  const { brandCta, foreground, background } =
    getCoverBrandThemeForSvgExport(themeId)
  const { family } = getCoverTheme(themeId)

  if (family === 'dark') {
    return [
      { offset: '0%', color: mixOklchHex(brandCta, foreground, 55) },
      { offset: '28%', color: mixOklchHex(brandCta, foreground, 88) },
      { offset: '62%', color: foreground },
      { offset: '100%', color: foreground },
    ]
  }

  return [
    { offset: '0%', color: mixOklchHex(brandCta, background, 45) },
    { offset: '18%', color: mixOklchHex(brandCta, foreground, 72) },
    { offset: '46%', color: foreground },
    { offset: '100%', color: foreground },
  ]
}
