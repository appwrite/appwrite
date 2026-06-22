import { readFile } from 'node:fs/promises'
import { join } from 'node:path'
import { decompress as decompressWoff2 } from 'wawoff2'

const PUBLIC_DIR = join(process.cwd(), 'public')

export type CoverOgFont = {
  name: string
  data: ArrayBuffer
  weight: 400 | 600
  style: 'normal'
}

let cachedFonts: CoverOgFont[] | null = null

async function loadOgFont(relativePath: string, weight: 400 | 600): Promise<CoverOgFont> {
  const woff2 = await readFile(join(PUBLIC_DIR, relativePath.replace(/^\/+/, '')))
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
