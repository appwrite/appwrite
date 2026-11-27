import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

/** Written by `bun run generate:content-dates`, relative to the package root. */
export const CONTENT_DATES_PATH = 'src/lib/sitemap/generated/content-dates.json'

/** Where page content lives; only changes under these paths date a page. */
export const CONTENT_DATE_SOURCES = [
  'src/content/docs',
  'src/components/pages',
  'src/lib/products/content',
  'src/lib/alternatives/content',
  'src/lib/pricing',
  'src/lib/solutions',
]

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..',
)

let cached: Map<string, string> | undefined

function readContentDates(): Map<string, string> {
  if (cached) return cached
  cached = new Map()
  try {
    const raw = fs.readFileSync(path.join(packageRoot, CONTENT_DATES_PATH), 'utf8')
    for (const [file, date] of Object.entries(JSON.parse(raw) as Record<string, string>)) {
      cached.set(file, date)
    }
  } catch {
    // No dates file (local builds): sitemap entries go out without <lastmod>.
  }
  return cached
}

/**
 * The newest last-change date across the given files and directories, as
 * `YYYY-MM-DD`, or `undefined` when none of them are recorded.
 */
export function getLastChanged(sources: readonly string[]): string | undefined {
  const dates = readContentDates()
  let newest: string | undefined

  for (const source of sources) {
    const prefix = `${source}/`
    for (const [file, date] of dates) {
      if ((file === source || file.startsWith(prefix)) && (!newest || date > newest)) {
        newest = date
      }
    }
  }

  return newest
}
