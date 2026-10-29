/**
 * Upscales blog and changelog cover images that are narrower than the
 * minimum width required for Google Discover (1200px).
 *
 * Scans:
 * - public/images/blog/<slug>/cover.*
 * - public/images/changelog/*.avif
 *
 * Covers below the minimum width are resized (aspect ratio preserved) and
 * re-encoded in place. Run `bun run generate:cover-manifest` afterwards so
 * og:image dimensions stay accurate.
 *
 * Run: bun run generate:content-covers
 */
import { readdirSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'
import { MIN_COVER_IMAGE_WIDTH } from '../src/lib/seo/cover-constants.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const PUBLIC_ROOT = join(__dirname, '..', 'public')

const COVER_FILE_PATTERN = /^cover\.(avif|png|jpe?g|webp)$/i

function listCoverFiles(): string[] {
  const files: string[] = []

  const blogRoot = join(PUBLIC_ROOT, 'images/blog')
  for (const slug of readdirSync(blogRoot)) {
    const dir = join(blogRoot, slug)
    if (!statSync(dir).isDirectory()) continue
    for (const file of readdirSync(dir)) {
      if (COVER_FILE_PATTERN.test(file)) {
        files.push(join(dir, file))
      }
    }
  }

  const changelogRoot = join(PUBLIC_ROOT, 'images/changelog')
  for (const file of readdirSync(changelogRoot)) {
    if (/\.(avif|png|jpe?g|webp)$/i.test(file)) {
      files.push(join(changelogRoot, file))
    }
  }

  return files
}

async function encodeForPath(pipeline: sharp.Sharp, path: string): Promise<Buffer> {
  if (/\.avif$/i.test(path)) {
    // Same encode settings as scripts/generate-blog-images.ts
    return pipeline
      .avif({ quality: 82, effort: 4, chromaSubsampling: '4:4:4' })
      .toBuffer()
  }
  if (/\.png$/i.test(path)) return pipeline.png().toBuffer()
  if (/\.webp$/i.test(path)) return pipeline.webp({ quality: 90 }).toBuffer()
  return pipeline.jpeg({ quality: 90 }).toBuffer()
}

async function main() {
  const files = listCoverFiles()
  let upscaled = 0

  for (const file of files) {
    const metadata = await sharp(file).metadata()
    const width = metadata.width ?? 0
    if (width === 0 || width >= MIN_COVER_IMAGE_WIDTH) continue

    const pipeline = sharp(file).resize({ width: MIN_COVER_IMAGE_WIDTH })
    const buffer = await encodeForPath(pipeline, file)
    await Bun.write(file, buffer)
    upscaled++
    console.log(
      `Upscaled ${file.replace(`${PUBLIC_ROOT}/`, '')} (${width}px -> ${MIN_COVER_IMAGE_WIDTH}px)`,
    )
  }

  console.log(`Checked ${files.length} covers, upscaled ${upscaled}.`)
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
