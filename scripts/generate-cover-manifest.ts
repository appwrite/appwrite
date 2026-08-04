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
import { readdirSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'
import { MIN_COVER_IMAGE_WIDTH } from '../src/lib/seo/cover-constants.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const PUBLIC_ROOT = join(__dirname, '..', 'public')
const OUTPUT_PATH = join(__dirname, '..', 'src', 'lib', 'seo', 'cover-dimensions.json')

const COVER_FILE_PATTERN = /^cover\.(avif|png|jpe?g|webp)$/i

function listCoverPublicPaths(): string[] {
  const paths: string[] = []

  for (const blogRoot of ['images/blog', 'images/blog-local']) {
    const root = join(PUBLIC_ROOT, blogRoot)
    for (const slug of readdirSync(root)) {
      const dir = join(root, slug)
      if (!statSync(dir).isDirectory()) continue
      for (const file of readdirSync(dir)) {
        if (COVER_FILE_PATTERN.test(file)) {
          paths.push(`/${blogRoot}/${slug}/${file}`)
        }
      }
    }
  }

  const changelogRoot = join(PUBLIC_ROOT, 'images/changelog')
  for (const file of readdirSync(changelogRoot)) {
    if (/\.(avif|png|jpe?g|webp)$/i.test(file)) {
      paths.push(`/images/changelog/${file}`)
    }
  }

  return paths.sort()
}

async function main() {
  const manifest: Record<string, string> = {}
  const undersized: string[] = []

  for (const publicPath of listCoverPublicPaths()) {
    const metadata = await sharp(join(PUBLIC_ROOT, publicPath.slice(1))).metadata()
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
