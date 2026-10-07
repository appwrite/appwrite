import sharp from 'sharp'
import { loadCoverImageBuffer } from '@/lib/cover-generator/brand-background'
import { stripCoverTitleSuffix } from '@/lib/cover-generator/text-utils'
import { getCoverTheme, type CoverThemeId } from '@/lib/cover-generator/themes'

export const COVER_DARK_LOGOTYPE_SRC = '/assets/logotype/dark@2x.png'
export const COVER_LIGHT_LOGOTYPE_SRC = '/assets/logotype/black.svg'

export const COVER_CONTENT_X = 40
export const COVER_SIMPLE_TITLE_LOGOTYPE_HEIGHT = 36
export const COVER_SIMPLE_TITLE_LOGOTYPE_TOP = 40
/** Space between logotype and the text block below (eyebrow or title). */
export const COVER_SIMPLE_TITLE_LOGOTYPE_GAP = 28

export function getCoverSimpleTitleMinTextTop(): number {
  return (
    COVER_SIMPLE_TITLE_LOGOTYPE_TOP +
    COVER_SIMPLE_TITLE_LOGOTYPE_HEIGHT +
    COVER_SIMPLE_TITLE_LOGOTYPE_GAP
  )
}

export function isCoverBrandWordmarkTitle(title: string): boolean {
  return (
    stripCoverTitleSuffix(title).localeCompare('Appwrite', undefined, {
      sensitivity: 'accent',
    }) === 0
  )
}

function getCoverLogotypeSrc(themeId: CoverThemeId): string {
  const family = getCoverTheme(themeId).family
  return family === 'light' ? COVER_LIGHT_LOGOTYPE_SRC : COVER_DARK_LOGOTYPE_SRC
}

export type PreparedCoverLogotype = {
  href: string
  width: number
  height: number
}

export async function prepareCoverLogotypeDataUri(
  themeId: CoverThemeId,
  displayHeight: number,
): Promise<PreparedCoverLogotype | null> {
  if (displayHeight <= 0) return null

  const buffer = await loadCoverImageBuffer(getCoverLogotypeSrc(themeId))
  if (!buffer) return null

  const png = await sharp(buffer, {
    density: Math.max(144, Math.ceil(displayHeight * 4)),
  })
    .resize({
      height: Math.round(displayHeight),
      fit: 'contain',
      background: { r: 0, g: 0, b: 0, alpha: 0 },
    })
    .png()
    .toBuffer({ resolveWithObject: true })

  const width = png.info.width
  const height = png.info.height
  if (!width || !height) return null

  return {
    href: `data:image/png;base64,${png.data.toString('base64')}`,
    width,
    height,
  }
}

export function renderCoverLogotypeSvg(
  logotype: PreparedCoverLogotype,
  x = COVER_CONTENT_X,
  y = COVER_SIMPLE_TITLE_LOGOTYPE_TOP,
): string {
  return `<image href="${logotype.href}" x="${x}" y="${y}" width="${logotype.width}" height="${logotype.height}" />`
}
