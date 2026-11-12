import type { CoverThemeFamily, CoverThemeId } from '@/lib/cover-generator/themes'
import {
  getCoverTheme,
  resolveCoverThemeId,
} from '@/lib/cover-generator/themes'

/** Canonical fill in `/public/icons` SVGs; Lucide stroke should match on dark themes. */
export const COVER_BRAND_ICON_COLOR = '#C4C6D7'

/** Matches light cover foreground (#09090b) for icon rasterization. */
export const COVER_LIGHT_ICON_TINT = { r: 9, g: 9, b: 11 } as const

export type CoverIconRgbTint = { r: number; g: number; b: number }

export function isCoverRasterTintedIconSource(source: string | undefined): boolean {
  return Boolean(source?.startsWith('/icons/') || source?.startsWith('lucide:'))
}

export function getCoverIconRasterTint(
  source: string | undefined,
  themeFamily?: CoverThemeFamily,
): CoverIconRgbTint | null {
  if (!source || !themeFamily || themeFamily !== 'light') return null
  if (!source.startsWith('/icons/') && !source.startsWith('lucide:')) return null

  return COVER_LIGHT_ICON_TINT
}

/** @deprecated Use getCoverIconRasterTint */
export function shouldDarkenCoverBuiltInIcon(
  source: string | undefined,
  themeFamily?: CoverThemeFamily,
): boolean {
  return getCoverIconRasterTint(source, themeFamily) != null
}

export function getCoverLucideIconStrokeColorForFamily(family: CoverThemeFamily): string {
  return family === 'light' ? '#000000' : COVER_BRAND_ICON_COLOR
}

export function getCoverLucideIconStrokeColor(
  themeId: CoverThemeId | string | null | undefined,
): string {
  const family = getCoverTheme(resolveCoverThemeId(themeId)).family
  return getCoverLucideIconStrokeColorForFamily(family)
}

export function getCoverLucideIconPreviewClasses(
  themeId: CoverThemeId | string | null | undefined,
): string {
  return getCoverIconPreviewClasses(themeId)
}

export function getCoverIconPreviewClassesForFamily(
  family: CoverThemeFamily,
): string {
  return family === 'light'
    ? 'brightness-0 opacity-[0.55]'
    : 'brightness-100 opacity-100'
}

export function getCoverLucideIconStrokeColorFromOptions(options: {
  themeId?: CoverThemeId | string | null
  themeFamily?: CoverThemeFamily
}): string {
  if (options.themeId != null && options.themeId !== '') {
    return getCoverLucideIconStrokeColor(options.themeId)
  }

  return getCoverLucideIconStrokeColorForFamily(options.themeFamily ?? 'light')
}

export function getCoverIconPreviewClasses(
  themeId: CoverThemeId | string | null | undefined,
): string {
  const family = getCoverTheme(themeId).family
  return getCoverIconPreviewClassesForFamily(family)
}
