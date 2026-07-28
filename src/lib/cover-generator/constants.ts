export {
  COVER_THEME_IDS as COVER_THEMES,
  DEFAULT_COVER_THEME_ID,
  isCoverThemeId as isCoverTheme,
} from '@/lib/cover-generator/themes'

export type { CoverThemeId as CoverTheme } from '@/lib/cover-generator/themes'

export const COVER_WIDTH = 1200
export const COVER_HEIGHT = 630

export const COVER_SIZE_PRESETS = [
  { id: 'og', label: 'Open Graph', width: 1200, height: 630 },
  { id: 'blog', label: 'Blog post (16:9)', width: 1920, height: 1080 },
  { id: 'twitter', label: 'Twitter / X', width: 1600, height: 900 },
  { id: 'square', label: 'Square', width: 1080, height: 1080 },
  { id: 'story', label: 'Story', width: 1080, height: 1920 },
  { id: 'twitter-header', label: 'Twitter header', width: 1500, height: 500 },
] as const

export type CoverSizePresetId = (typeof COVER_SIZE_PRESETS)[number]['id']

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

  return { width: COVER_WIDTH, height: COVER_HEIGHT }
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
] as const

export type CoverTemplateId = (typeof COVER_TEMPLATE_IDS)[number]

export const COVER_IMAGE_FORMATS = ['png', 'jpeg', 'avif'] as const
export type CoverImageFormat = (typeof COVER_IMAGE_FORMATS)[number]

export function isCoverTemplateId(value: string): value is CoverTemplateId {
  return (COVER_TEMPLATE_IDS as readonly string[]).includes(value)
}
