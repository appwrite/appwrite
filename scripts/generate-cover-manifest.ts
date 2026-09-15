/**
 * Generates src/lib/seo/cover-dimensions.json: a map of public cover image
 * paths to their real pixel dimensions ("WIDTHxHEIGHT"). Used at runtime to
 * emit accurate og:image:width / og:image:height meta tags for blog posts and
 * changelog entries.
 *
 * Also warns about covers below the Google Discover minimum width; fix those
 * with `bun run generate:content-covers`.
 *
 * Run: bun run generate:cover-manifest
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'
import { MIN_COVER_IMAGE_WIDTH } from '../src/lib/seo/cover-constants.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')
const PUBLIC_ROOT = join(ROOT, 'public')
const CONTENT_ROOT = join(ROOT, 'src', 'content')
const OUTPUT_PATH = join(ROOT, 'src', 'lib', 'seo', 'cover-dimensions.json')

const COVER_FRONTMATTER_PATTERN = /^cover:\s+(\/images\/[^\s]+)\s*$/gm

function collectCoverPathsFromContent(): string[] {
  const paths = new Set<string>()

  function walk(dir: string) {
    for (const entry of readdirSync(dir)) {
      const entryPath = join(dir, entry)
      const stat = statSync(entryPath)
      if (stat.isDirectory()) {
        walk(entryPath)
        continue
      }
      if (!entry.endsWith('.markdoc')) continue

      const source = readFileSync(entryPath, 'utf8')
      for (const match of source.matchAll(COVER_FRONTMATTER_PATTERN)) {
        paths.add(match[1]!)
      }
    }
  }

  walk(CONTENT_ROOT)
  return [...paths].sort()
}

async function main() {
  const manifest: Record<string, string> = {}
  const undersized: string[] = []
  const missing: string[] = []

  for (const publicPath of collectCoverPathsFromContent()) {
    const filePath = join(PUBLIC_ROOT, publicPath.slice(1))
    if (!statSync(filePath, { throwIfNoEntry: false })) {
      missing.push(publicPath)
      continue
    }

    const metadata = await sharp(filePath).metadata()
    const width = metadata.width ?? 0
    const height = metadata.height ?? 0
    if (!width || !height) continue

    manifest[publicPath] = `${width}x${height}`
    if (width < MIN_COVER_IMAGE_WIDTH) {
      undersized.push(`${publicPath} (${width}x${height})`)
    }
  }

  await Bun.write(OUTPUT_PATH, `${JSON.stringify(manifest, null, 2)}\n`)
  console.log(`Wrote ${Object.keys(manifest).length} cover dimensions to ${OUTPUT_PATH}`)

  if (missing.length > 0) {
    console.warn(`WARNING: ${missing.length} content cover paths are missing on disk:`)
    for (const entry of missing) {
      console.warn(`  ${entry}`)
    }
  }

  if (undersized.length > 0) {
    console.warn(
      `WARNING: ${undersized.length} covers are below ${MIN_COVER_IMAGE_WIDTH}px wide (bad for Google Discover). Fix with: bun run generate:content-covers`,
    )
    for (const entry of undersized) {
      console.warn(`  ${entry}`)
    }
  }
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
