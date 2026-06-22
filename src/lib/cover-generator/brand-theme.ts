export {
  COVER_THEME_DEFINITIONS,
  COVER_THEME_IDS,
  DEFAULT_COVER_THEME_ID,
  getCoverBrandTheme,
  getCoverBrandThemeForSvgExport,
  getCoverTheme,
  isCoverThemeId,
  listCoverThemes,
  listCoverThemesByFamily,
  resolveCoverThemeId,
} from '@/lib/cover-generator/themes'
export { getCoverTitleGradientStyle } from '@/lib/cover-generator/cover-title-gradient'

export type {
  CoverBackgroundGridStyle,
  CoverBrandTheme,
  CoverSoftLightOpacity,
  CoverSoftLightVariant,
  CoverThemeDefinition,
  CoverThemeFamily,
  CoverThemeId,
} from '@/lib/cover-generator/themes'
export type {
  CoverSoftLightOpacityLevel,
  CoverSoftLightTone,
} from '@/lib/cover-generator/cover-brand-lights'
