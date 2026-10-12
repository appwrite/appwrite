import { COVER_EXPORT_TTF_SOURCES } from '@/lib/cover-generator/cover-font-paths'
import { readCoverPublicAssetBuffer } from '@/lib/cover-generator/public-assets'

let cachedFontFaceCss: string | null = null
const ttfDataUriCache = new Map<string, string>()

/**
 * librsvg (Sharp SVG export) ignores @font-face data URIs on Linux.
 * Docker installs TTFs for fontconfig instead (see Dockerfile).
 * macOS dev may still benefit from embedded faces when system fonts are absent.
 */
function shouldEmbedCoverFontsInSvg(): boolean {
  return process.platform !== 'linux'
}

async function readFontTtfDataUri(relativePath: string): Promise<string> {
  const normalized = relativePath.replace(/^\/+/, '')
  const cached = ttfDataUriCache.get(normalized)
  if (cached) return cached

  const ttf = await readCoverPublicAssetBuffer(normalized)
  if (!ttf) {
    throw new Error(`Cover export font not found: ${normalized}`)
  }

  const dataUri = `data:font/truetype;base64,${ttf.toString('base64')}`
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
  if (cachedFontFaceCss !== null) return cachedFontFaceCss

  if (!shouldEmbedCoverFontsInSvg()) {
    cachedFontFaceCss = ''
    return cachedFontFaceCss
  }

  const [aeonikRegular, aeonikMedium, interRegular, interSemibold] =
    await Promise.all([
      readFontTtfDataUri(COVER_EXPORT_TTF_SOURCES.aeonikRegular),
      readFontTtfDataUri(COVER_EXPORT_TTF_SOURCES.aeonikMedium),
      readFontTtfDataUri(COVER_EXPORT_TTF_SOURCES.interRegular),
      readFontTtfDataUri(COVER_EXPORT_TTF_SOURCES.interSemibold),
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
