import { parseFont, type DocumentFonts, type Font } from './pdf'

/**
 * Brand typography for exported reports: Aeonik Pro for headings and large
 * figures, Inter for body copy (matching the console's --font-aeonik-pro /
 * --font-inter). The TTFs ship in `public/fonts-ttf/`, the same files the
 * cover generator uses.
 */
const REPORT_FONT_FILES: Record<Font, { path: string; name: string }> = {
  heading: { path: '/fonts-ttf/AeonikPro-Medium.ttf', name: 'AeonikPro-Medium' },
  regular: { path: '/fonts-ttf/Inter-Regular.ttf', name: 'Inter-Regular' },
  bold: { path: '/fonts-ttf/Inter-SemiBold.ttf', name: 'Inter-SemiBold' },
}

let cached: Promise<DocumentFonts> | null = null

/**
 * Load and parse the report fonts once per session. A font that fails to
 * load is simply omitted, and the PDF writer falls back to Helvetica for it,
 * so an export never fails over typography.
 */
export function loadReportFonts(): Promise<DocumentFonts> {
  cached ??= (async () => {
    const entries = await Promise.all(
      (Object.keys(REPORT_FONT_FILES) as Font[]).map(async (font) => {
        const { path, name } = REPORT_FONT_FILES[font]
        try {
          const response = await fetch(new URL(path, window.location.origin))
          if (!response.ok) return null
          const bytes = new Uint8Array(await response.arrayBuffer())
          return [font, parseFont(bytes, name)] as const
        } catch {
          return null
        }
      }),
    )
    const fonts: DocumentFonts = {}
    for (const entry of entries) if (entry) fonts[entry[0]] = entry[1]
    // Don't cache a total failure (e.g. offline): retry on the next export.
    if (Object.keys(fonts).length === 0) cached = null
    return fonts
  })()
  return cached
}
