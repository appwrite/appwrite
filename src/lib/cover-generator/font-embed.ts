import { decompress as decompressWoff2 } from 'wawoff2'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'

const AEONIK_REGULAR = 'fonts/aeonik-pro/AeonikPro-Regular.woff2'
const AEONIK_MEDIUM = 'fonts/aeonik-pro/AeonikPro-Medium.woff2'
const INTER_REGULAR = 'fonts/inter/inter-latin-400-normal.woff2'
const INTER_SEMIBOLD = 'fonts/inter/inter-latin-600-normal.woff2'

let cachedFontFaceCss: string | null = null
const ttfDataUriCache = new Map<string, string>()

/** librsvg (Sharp SVG export) only renders embedded TrueType/OpenType, not WOFF2. */
async function readFontTtfDataUri(relativePath: string): Promise<string> {
  const normalized = relativePath.replace(/^\/+/, '')
  const cached = ttfDataUriCache.get(normalized)
  if (cached) return cached

  const woff2 = await readCoverPublicAssetBuffer(normalized)
  if (!woff2) {
    throw new Error(`Cover export font not found: ${normalized}`)
  }

  const ttf = await decompressWoff2(new Uint8Array(woff2))
  const dataUri = `data:font/truetype;base64,${Buffer.from(ttf).toString('base64')}`
  ttfDataUriCache.set(normalized, dataUri)
  return dataUri
}

function buildFontFaceRule(family: string, weight: number, src: string): string {
  return `
    @font-face {
      font-family: '${family}';
      font-style: normal;
      font-weight: ${weight};
      src: url('${src}') format('truetype');
    }`
}

export async function getCoverFontFaceCss(): Promise<string> {
  if (cachedFontFaceCss) return cachedFontFaceCss

  const [aeonikRegular, aeonikMedium, interRegular, interSemibold] =
    await Promise.all([
      readFontTtfDataUri(AEONIK_REGULAR),
      readFontTtfDataUri(AEONIK_MEDIUM),
      readFontTtfDataUri(INTER_REGULAR),
      readFontTtfDataUri(INTER_SEMIBOLD),
    ])

  cachedFontFaceCss = [
    buildFontFaceRule('Aeonik Pro', 400, aeonikRegular),
    buildFontFaceRule('Aeonik Pro', 500, aeonikMedium),
    buildFontFaceRule('Aeonik Pro', 600, aeonikMedium),
    buildFontFaceRule('Inter', 400, interRegular),
    buildFontFaceRule('Inter', 600, interSemibold),
  ].join('\n')

  return cachedFontFaceCss
}
