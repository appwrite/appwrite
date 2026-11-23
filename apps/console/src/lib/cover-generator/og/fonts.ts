import { COVER_EXPORT_TTF_SOURCES } from '@/lib/cover-generator/cover-font-paths'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'

export type CoverOgFont = {
  name: string
  data: ArrayBuffer
  weight: 400 | 600
  style: 'normal'
}

let cachedFonts: CoverOgFont[] | null = null

async function loadOgFont(relativePath: string, weight: 400 | 600): Promise<CoverOgFont> {
  const ttf = await readCoverPublicAssetBuffer(relativePath)
  if (!ttf) {
    throw new Error(`Cover OG font not found: ${relativePath}`)
  }
  return {
    name: 'Aeonik Pro',
    data: Uint8Array.from(ttf).buffer,
    weight,
    style: 'normal',
  }
}

export async function loadCoverOgFonts(): Promise<CoverOgFont[]> {
  if (cachedFonts) return cachedFonts

  cachedFonts = await Promise.all([
    loadOgFont(COVER_EXPORT_TTF_SOURCES.aeonikRegular, 400),
    loadOgFont(COVER_EXPORT_TTF_SOURCES.aeonikMedium, 600),
  ])

  return cachedFonts
}
