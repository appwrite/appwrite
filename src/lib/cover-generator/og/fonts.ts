import { decompress as decompressWoff2 } from 'wawoff2'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'

export type CoverOgFont = {
  name: string
  data: ArrayBuffer
  weight: 400 | 600
  style: 'normal'
}

let cachedFonts: CoverOgFont[] | null = null

async function loadOgFont(relativePath: string, weight: 400 | 600): Promise<CoverOgFont> {
  const woff2 = await readCoverPublicAssetBuffer(relativePath)
  if (!woff2) {
    throw new Error(`Cover OG font not found: ${relativePath}`)
  }
  const ttf = await decompressWoff2(new Uint8Array(woff2))
  return {
    name: 'Aeonik Pro',
    data: ttf.buffer.slice(ttf.byteOffset, ttf.byteOffset + ttf.byteLength),
    weight,
    style: 'normal',
  }
}

export async function loadCoverOgFonts(): Promise<CoverOgFont[]> {
  if (cachedFonts) return cachedFonts

  cachedFonts = await Promise.all([
    loadOgFont('fonts/aeonik-pro/AeonikPro-Regular.woff2', 400),
    loadOgFont('fonts/aeonik-pro/AeonikPro-Medium.woff2', 600),
  ])

  return cachedFonts
}
