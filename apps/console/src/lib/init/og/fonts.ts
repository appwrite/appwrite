import { COVER_EXPORT_TTF_SOURCES } from '@/lib/cover-generator/cover-font-paths'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'

export const INIT_TICKET_OG_FONT_INTER = 'Inter'

export type InitTicketOgFont = {
  name: string
  data: ArrayBuffer
  weight: 400 | 600
  style: 'normal'
}

let cachedFonts: InitTicketOgFont[] | null = null

async function loadOgFont(
  relativePath: string,
  weight: 400 | 600,
): Promise<InitTicketOgFont> {
  const ttf = await readCoverPublicAssetBuffer(relativePath)
  if (!ttf) {
    throw new Error(`Init ticket OG font not found: ${relativePath}`)
  }
  return {
    name: INIT_TICKET_OG_FONT_INTER,
    data: Uint8Array.from(ttf).buffer,
    weight,
    style: 'normal',
  }
}

export async function loadInitTicketOgFonts(): Promise<InitTicketOgFont[]> {
  if (cachedFonts) return cachedFonts

  cachedFonts = await Promise.all([
    loadOgFont(COVER_EXPORT_TTF_SOURCES.interRegular, 400),
    loadOgFont(COVER_EXPORT_TTF_SOURCES.interSemibold, 600),
  ])

  return cachedFonts
}
