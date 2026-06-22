/**
 * Converts hand-authored cover PNGs to AVIF for vibes-native blog posts.
 *
 * Place `cover-source.png` in public/images/blog-local/<slug>/, then run:
 * bun run scripts/generate-blog-local-images.ts [slug]
 */
import { access, mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

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

const IMAGE_GENERATORS: Record<string, (outputDir: string) => Promise<void>> = {
  'announcing-console-terminal': convertCoverSourceToAvif,
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
  console.log(`Done. Cover saved to public/images/blog-local/${slug}/cover.avif`)
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
