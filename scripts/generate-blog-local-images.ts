/**
 * Generates or converts cover images for vibes-native blog posts.
 *
 * Hand-authored covers: place `cover-source.png` in
 * `public/images/blog-local/<slug>/`, then run this script.
 *
 * Generated covers: slugs with a generator function write `cover-source.png`
 * and `cover.avif` automatically.
 *
 * Run: bun run generate:blog-local-images [slug]
 */
import { access, mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'
import { resolveCoverSizePresetKey } from '../src/lib/cover-generator/constants.ts'
import { renderCoverImage } from '../src/lib/cover-generator/render-cover.ts'
import type { CoverRenderData } from '../src/lib/cover-generator/types.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '..')

async function exists(path: string): Promise<boolean> {
  try {
    await access(path)
    return true
  } catch {
    return false
  }
}

async function writeAvifFromPng(outputDir: string, png: Uint8Array): Promise<void> {
  writeFileSync(join(outputDir, 'cover-source.png'), png)

  const avif = await sharp(Buffer.from(png))
    .avif({ quality: 82, effort: 4, chromaSubsampling: '4:4:4' })
    .toBuffer()

  writeFileSync(join(outputDir, 'cover.avif'), avif)
  console.log(`Wrote cover-source.png and cover.avif (${avif.length} bytes)`)
}

async function convertCoverSourceToAvif(outputDir: string): Promise<void> {
  mkdirSync(outputDir, { recursive: true })

  const sourcePath = join(outputDir, 'cover-source.png')
  if (!(await exists(sourcePath))) {
    throw new Error(`Missing cover source: ${sourcePath}`)
  }

  const avif = await sharp(sourcePath)
    .avif({ quality: 82, effort: 4, chromaSubsampling: '4:4:4' })
    .toBuffer()

  writeFileSync(join(outputDir, 'cover.avif'), avif)
  console.log(`Wrote cover.avif (${avif.length} bytes)`)
}

async function generateAnnouncingAppwriteExplorerCover(
  outputDir: string,
): Promise<void> {
  mkdirSync(outputDir, { recursive: true })

  const { width, height } = resolveCoverSizePresetKey('blog')

  const data: CoverRenderData = {
    template: 'simple-title',
    theme: 'dark',
    format: 'png',
    width,
    height,
    title: 'Introducing Appwrite Explorer',
    subtitle: 'Browse, build, and send Appwrite API requests from the Console',
    eyebrow: 'Product update',
  }

  const png = await renderCoverImage(data)
  await writeAvifFromPng(outputDir, png)
}

const IMAGE_GENERATORS: Record<string, (outputDir: string) => Promise<void>> = {
  'announcing-console-terminal': convertCoverSourceToAvif,
  'announcing-appwrite-explorer': generateAnnouncingAppwriteExplorerCover,
}

async function main() {
  const slug = process.argv[2] ?? 'announcing-console-terminal'
  const generate = IMAGE_GENERATORS[slug]
  if (!generate) {
    console.error(`Unknown blog-local slug: ${slug}`)
    console.error(`Available: ${Object.keys(IMAGE_GENERATORS).join(', ')}`)
    process.exit(1)
  }

  const outputDir = join(VIBES_ROOT, 'public', 'images', 'blog-local', slug)
  console.log(`Generating cover for ${slug}...`)
  await generate(outputDir)
  console.log(`Done. Cover saved to public/images/blog-local/${slug}/`)
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
