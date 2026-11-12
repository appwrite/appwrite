import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'
import { buildCoverBrandBackgroundSvgLayers } from '@/lib/cover-generator/cover-soft-lights'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

/**
 * Full background SVG for OG/Satori (dot grid under soft lights).
 * CSS radial gradients are not reliable in Satori; this matches the homepage hero wash.
 */
export function buildCoverOgBackgroundSvg(
  themeId: CoverThemeId,
  width = COVER_WIDTH,
  height = COVER_HEIGHT,
): string {
  const { defs, layers } = buildCoverBrandBackgroundSvgLayers(themeId, width, height)

  return `
    <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
      <defs>${defs}</defs>
      ${layers}
    </svg>
  `.trim()
}

export function buildCoverOgBackgroundDataUri(
  themeId: CoverThemeId,
  width = COVER_WIDTH,
  height = COVER_HEIGHT,
): string {
  const svg = buildCoverOgBackgroundSvg(themeId, width, height)
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}
