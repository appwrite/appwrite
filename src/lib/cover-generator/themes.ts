/**
 * Cover themes are independent from the app UI theme.
 * Values below are initialized from the default app marketing palette but are
 * owned, versioned, and extended here (multiple light/dark variants).
 */

import type { CoverSoftLightOpacity } from '@/lib/cover-generator/cover-brand-lights'
import { getCoverSoftLightOpacityPreset } from '@/lib/cover-generator/cover-brand-lights'
import { cssColorToHex } from '@/lib/cover-generator/cover-oklch-mix'

export type { CoverSoftLightOpacity, CoverSoftLightTone } from '@/lib/cover-generator/cover-brand-lights'

export type CoverThemeFamily = 'light' | 'dark'

export type CoverBackgroundGridStyle = 'dots' | 'grid' | 'diagonal' | 'none'

export type CoverSoftLightVariant = 'hero' | 'aurora' | 'beam' | 'glow'

export const COVER_BACKGROUND_GRID_LABELS: Record<CoverBackgroundGridStyle, string> = {
  dots: 'Dotted grid',
  grid: 'Line grid',
  diagonal: 'Diagonal lines',
  none: 'Plain',
}

/** Unique label per theme: background pattern plus light placement and colors. */
const COVER_THEME_LABEL_BY_ID: Record<string, string> = {
  light: 'Dotted grid, bottom pink & purple',
  dark: 'Dotted grid, bottom pink & purple',
  'light-aurora': 'Dotted grid, top teal & purple aurora',
  'dark-aurora': 'Dotted grid, top teal & purple aurora',
  'light-grid': 'Line grid, bottom purple & teal',
  'dark-grid': 'Line grid, bottom purple & teal',
  'light-beam': 'Diagonal lines, top pink & orange spotlight',
  'dark-beam': 'Diagonal lines, top pink & orange spotlight',
  'light-plain': 'Plain, bottom pink & purple',
  'dark-plain': 'Plain, bottom pink & purple',
  'dark-glow': 'Plain, bottom teal ambient glow',
}

const COVER_THEME_DESCRIPTION_BY_ID: Record<string, string> = {
  light: 'Classic brand hero wash from the lower corners.',
  dark: 'Classic brand hero wash from the lower corners.',
  'light-aurora': 'Mint teal and purple aurora from the upper edge.',
  'dark-aurora': 'Mint teal and purple aurora from the upper edge.',
  'light-grid': 'Square line grid with purple and mint teal corner glow.',
  'dark-grid': 'Square line grid with purple and mint teal corner glow.',
  'light-beam': 'Diagonal texture with a pink spotlight and orange accent above.',
  'dark-beam': 'Diagonal texture with a pink spotlight and orange accent above.',
  'light-plain': 'Solid background with soft corner glow, no texture.',
  'dark-plain': 'Solid background with soft corner glow, no texture.',
  'dark-glow':
    'Plain dark background with a large teal light leak from the lower-left corner.',
}

export function getCoverBackgroundGridLabel(style: CoverBackgroundGridStyle): string {
  return COVER_BACKGROUND_GRID_LABELS[style]
}

export type CoverThemeDefinition = {
  id: string
  label: string
  description: string
  family: CoverThemeFamily
  background: string
  foreground: string
  muted: string
  mutedForeground: string
  border: string
  brandCta: string
  brandPurple: string
  brandTeal: string
  backgroundGrid: CoverBackgroundGridStyle
  softLightVariant: CoverSoftLightVariant
  softLights: CoverSoftLightOpacity
}

const BRAND_CTA = '#FD366E'
const BRAND_PURPLE = '#7C67FE'
const BRAND_TEAL = '#85DBD8'
const BRAND_ORANGE = '#FE9567'

const LIGHT_SOFT_LIGHTS = getCoverSoftLightOpacityPreset('light')
const DARK_SOFT_LIGHTS = getCoverSoftLightOpacityPreset('dark')
const LIGHT_AURORA_SOFT_LIGHTS = getCoverSoftLightOpacityPreset('light', 1.2)
const DARK_AURORA_SOFT_LIGHTS = getCoverSoftLightOpacityPreset('dark', 1.25)
const LIGHT_BEAM_SOFT_LIGHTS = getCoverSoftLightOpacityPreset('light', 1.1)
const DARK_BEAM_SOFT_LIGHTS = getCoverSoftLightOpacityPreset('dark', 1.15)
const DARK_GLOW_SOFT_LIGHTS: CoverSoftLightOpacity = {
  pink: { strong: 0.06, mid: 0.02 },
  purple: { strong: 0.07, mid: 0.025 },
  teal: { strong: 0.13, mid: 0.045 },
  orange: { strong: 0.06, mid: 0.02 },
}

const LIGHT_BASE = {
  family: 'light' as const,
  background: 'oklch(1 0 0)',
  foreground: 'oklch(0.141 0.005 285.823)',
  mutedForeground: 'oklch(0.552 0.016 285.938)',
  muted: 'oklch(0.967 0.001 286.375)',
  border: 'oklch(0.93 0.0035 286.32)',
  brandCta: BRAND_CTA,
  brandPurple: BRAND_PURPLE,
  brandTeal: BRAND_TEAL,
}

const DARK_BASE = {
  family: 'dark' as const,
  background: '#19191c',
  foreground: 'oklch(0.985 0 0)',
  mutedForeground: 'oklch(0.705 0.015 286.067)',
  muted: 'oklch(0.28 0.01 250)',
  border: 'oklch(0.252 0.005 286.32)',
  brandCta: BRAND_CTA,
  brandPurple: BRAND_PURPLE,
  brandTeal: BRAND_TEAL,
}

type CoverThemeInput = Omit<CoverThemeDefinition, 'label' | 'description'>

function defineCoverTheme(theme: CoverThemeInput): CoverThemeDefinition {
  return {
    ...theme,
    label: COVER_THEME_LABEL_BY_ID[theme.id] ?? COVER_BACKGROUND_GRID_LABELS[theme.backgroundGrid],
    description:
      COVER_THEME_DESCRIPTION_BY_ID[theme.id] ??
      'Brand background with soft light wash.',
  }
}

export const COVER_THEME_DEFINITIONS = {
  light: defineCoverTheme({
    id: 'light',
    ...LIGHT_BASE,
    backgroundGrid: 'dots',
    softLightVariant: 'hero',
    softLights: LIGHT_SOFT_LIGHTS,
  }),
  dark: defineCoverTheme({
    id: 'dark',
    ...DARK_BASE,
    backgroundGrid: 'dots',
    softLightVariant: 'hero',
    softLights: DARK_SOFT_LIGHTS,
  }),
  'light-grid': defineCoverTheme({
    id: 'light-grid',
    ...LIGHT_BASE,
    backgroundGrid: 'grid',
    softLightVariant: 'hero',
    softLights: LIGHT_SOFT_LIGHTS,
  }),
  'dark-grid': defineCoverTheme({
    id: 'dark-grid',
    ...DARK_BASE,
    backgroundGrid: 'grid',
    softLightVariant: 'hero',
    softLights: DARK_SOFT_LIGHTS,
  }),
  'light-aurora': defineCoverTheme({
    id: 'light-aurora',
    ...LIGHT_BASE,
    backgroundGrid: 'dots',
    softLightVariant: 'aurora',
    softLights: LIGHT_AURORA_SOFT_LIGHTS,
  }),
  'dark-aurora': defineCoverTheme({
    id: 'dark-aurora',
    ...DARK_BASE,
    backgroundGrid: 'dots',
    softLightVariant: 'aurora',
    softLights: DARK_AURORA_SOFT_LIGHTS,
  }),
  'light-beam': defineCoverTheme({
    id: 'light-beam',
    ...LIGHT_BASE,
    backgroundGrid: 'diagonal',
    softLightVariant: 'beam',
    softLights: LIGHT_BEAM_SOFT_LIGHTS,
  }),
  'dark-beam': defineCoverTheme({
    id: 'dark-beam',
    ...DARK_BASE,
    backgroundGrid: 'diagonal',
    softLightVariant: 'beam',
    softLights: DARK_BEAM_SOFT_LIGHTS,
  }),
  'light-plain': defineCoverTheme({
    id: 'light-plain',
    ...LIGHT_BASE,
    backgroundGrid: 'none',
    softLightVariant: 'hero',
    softLights: LIGHT_SOFT_LIGHTS,
  }),
  'dark-plain': defineCoverTheme({
    id: 'dark-plain',
    ...DARK_BASE,
    backgroundGrid: 'none',
    softLightVariant: 'hero',
    softLights: DARK_SOFT_LIGHTS,
  }),
  'dark-glow': defineCoverTheme({
    id: 'dark-glow',
    ...DARK_BASE,
    backgroundGrid: 'none',
    softLightVariant: 'glow',
    softLights: DARK_GLOW_SOFT_LIGHTS,
  }),
} as const satisfies Record<string, CoverThemeDefinition>

export type CoverThemeId = keyof typeof COVER_THEME_DEFINITIONS

export const COVER_THEME_IDS = Object.keys(COVER_THEME_DEFINITIONS) as CoverThemeId[]

export const COVER_EDITOR_THEME_IDS = [
  'light-plain',
  'dark-plain',
  'dark-glow',
] as const satisfies readonly CoverThemeId[]

export type CoverEditorThemeId = (typeof COVER_EDITOR_THEME_IDS)[number]

export const DEFAULT_COVER_THEME_ID: CoverThemeId = 'dark-plain'

export function isCoverThemeId(value: string): value is CoverThemeId {
  return (COVER_THEME_IDS as readonly string[]).includes(value)
}

export function resolveCoverThemeId(
  themeId: CoverThemeId | string | null | undefined,
): CoverThemeId {
  if (typeof themeId === 'string' && isCoverThemeId(themeId)) {
    return themeId
  }

  return DEFAULT_COVER_THEME_ID
}

export function getCoverTheme(
  themeId: CoverThemeId | string | null | undefined,
): CoverThemeDefinition {
  return COVER_THEME_DEFINITIONS[resolveCoverThemeId(themeId)]
}

export function isCoverEditorThemeId(value: string): value is CoverEditorThemeId {
  return (COVER_EDITOR_THEME_IDS as readonly string[]).includes(value)
}

export function resolveCoverEditorThemeId(
  themeId: CoverThemeId | string | null | undefined,
): CoverEditorThemeId {
  const resolved = resolveCoverThemeId(themeId)
  if (isCoverEditorThemeId(resolved)) return resolved
  return getCoverTheme(resolved).family === 'dark' ? 'dark-plain' : 'light-plain'
}

export function listCoverThemes(): CoverThemeDefinition[] {
  return COVER_THEME_IDS.map((id) => COVER_THEME_DEFINITIONS[id])
}

export function listCoverEditorThemes(): CoverThemeDefinition[] {
  return COVER_EDITOR_THEME_IDS.map((id) => COVER_THEME_DEFINITIONS[id])
}

export function listCoverThemesByFamily(family: CoverThemeFamily): CoverThemeDefinition[] {
  return listCoverThemes().filter((theme) => theme.family === family)
}

export function listCoverEditorThemesByFamily(
  family: CoverThemeFamily,
): CoverThemeDefinition[] {
  return listCoverEditorThemes().filter((theme) => theme.family === family)
}

/** Rendering tokens used by templates and preview (subset of theme definition). */
export type CoverBrandTheme = Pick<
  CoverThemeDefinition,
  | 'background'
  | 'foreground'
  | 'muted'
  | 'mutedForeground'
  | 'border'
  | 'brandCta'
  | 'brandPurple'
  | 'brandTeal'
  | 'softLights'
>

export function getCoverBrandTheme(themeId: CoverThemeId): CoverBrandTheme {
  const theme = getCoverTheme(themeId)
  return {
    background: theme.background,
    foreground: theme.foreground,
    muted: theme.muted,
    mutedForeground: theme.mutedForeground,
    border: theme.border,
    brandCta: theme.brandCta,
    brandPurple: theme.brandPurple,
    brandTeal: theme.brandTeal,
    softLights: theme.softLights,
  }
}

/**
 * Hex tokens for Sharp/librsvg SVG rasterization.
 * Converts theme oklch tokens to hex so preview and export match.
 */
export function getCoverBrandThemeForSvgExport(
  themeId: CoverThemeId,
): CoverBrandTheme {
  const theme = getCoverTheme(themeId)
  return {
    background: cssColorToHex(theme.background),
    foreground: cssColorToHex(theme.foreground),
    muted: cssColorToHex(theme.muted),
    mutedForeground: cssColorToHex(theme.mutedForeground),
    border: cssColorToHex(theme.border),
    brandCta: cssColorToHex(theme.brandCta),
    brandPurple: cssColorToHex(theme.brandPurple),
    brandTeal: cssColorToHex(theme.brandTeal),
    softLights: theme.softLights,
  }
}
