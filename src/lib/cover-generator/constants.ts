import { COVER_EXTRA_TEMPLATE_IDS } from '@/lib/cover-generator/extra-templates/ids'

export {
  COVER_THEME_IDS as COVER_THEMES,
  DEFAULT_COVER_THEME_ID,
  isCoverThemeId as isCoverTheme,
} from '@/lib/cover-generator/themes'

export type { CoverThemeId as CoverTheme } from '@/lib/cover-generator/themes'

/**
 * Layout artboard size (16:9). Templates compose in this space, then scale
 * uniformly into the selected export canvas. Matches blog cover aspect ratio
 * (`BLOG_COVER_ASPECT_CLASS` / Google Discover-friendly covers).
 */
export const COVER_WIDTH = 1200
export const COVER_HEIGHT = 675

export const COVER_SIZE_PRESETS = [
  { id: 'blog', label: 'Blog', width: 1920, height: 1080 },
  { id: 'og', label: 'Open Graph', width: 1200, height: 630 },
  { id: 'twitter', label: 'Twitter / X', width: 1600, height: 900 },
  { id: 'square', label: 'Square', width: 1080, height: 1080 },
  { id: 'story', label: 'Story', width: 1080, height: 1920 },
  { id: 'twitter-header', label: 'Twitter header', width: 1500, height: 500 },
] as const

export type CoverSizePresetId = (typeof COVER_SIZE_PRESETS)[number]['id']

/** Default export: blog cover at 1920×1080 (16:9, ≥1200px wide for Discover). */
export const DEFAULT_COVER_SIZE_PRESET_ID = 'blog' satisfies CoverSizePresetId

export function getDefaultCoverSize(): { width: number; height: number } {
  const preset = COVER_SIZE_PRESETS.find((item) => item.id === DEFAULT_COVER_SIZE_PRESET_ID)
  return preset
    ? { width: preset.width, height: preset.height }
    : { width: COVER_WIDTH, height: COVER_HEIGHT }
}

export function getCoverSizePresetKey(width: number, height: number): string {
  const match = COVER_SIZE_PRESETS.find(
    (preset) => preset.width === width && preset.height === height,
  )
  return match?.id ?? `${width}x${height}`
}

export function resolveCoverSizePresetKey(key: string): { width: number; height: number } {
  const preset = COVER_SIZE_PRESETS.find((item) => item.id === key)
  if (preset) return { width: preset.width, height: preset.height }

  const [widthRaw, heightRaw] = key.split('x')
  const width = Number(widthRaw)
  const height = Number(heightRaw)
  if (
    Number.isFinite(width) &&
    Number.isFinite(height) &&
    width >= 320 &&
    width <= 4096 &&
    height >= 200 &&
    height <= 4096
  ) {
    return { width: Math.round(width), height: Math.round(height) }
  }

  return getDefaultCoverSize()
}

export const COVER_TEMPLATE_IDS = [
  'simple-title',
  'integration',
  'integration-icon',
  'showcase-icon',
  'title-icon',
  'screenshot',
  'screenshot-side',
  'screenshot-angled',
  'cards-angled',
  'table',
  'bar-chart',
  'line-chart',
  'cli-code',
  'code-snippet',
  'milestone-split',
  'milestone-centered',
  'version-number',
  'version-title',
  ...COVER_EXTRA_TEMPLATE_IDS,
] as const

export type CoverTemplateId = (typeof COVER_TEMPLATE_IDS)[number]

export const COVER_IMAGE_FORMATS = ['png', 'jpeg', 'avif'] as const
export type CoverImageFormat = (typeof COVER_IMAGE_FORMATS)[number]

export function isCoverTemplateId(value: string): value is CoverTemplateId {
  return (COVER_TEMPLATE_IDS as readonly string[]).includes(value)
}
