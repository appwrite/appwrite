/**
 * Generates or converts cover images for blog posts.
 *
 * Hand-authored covers: place `cover-source.png` in
 * `public/images/blog/<slug>/`, then run this script.
 *
 * Generated covers: slugs with a generator function write `cover-source.png`
 * and `cover.avif` automatically.
 *
 * Run: bun run generate:blog-images [slug]
 */
import { mkdirSync, writeFileSync } from 'node:fs'
import { access } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'
import { resolveCoverSizePresetKey } from '../src/lib/cover-generator/constants.ts'
import { renderCoverImage } from '../src/lib/cover-generator/render-cover.ts'
import type { CoverRenderData } from '../src/lib/cover-generator/types.ts'
import { MIN_COVER_IMAGE_WIDTH } from '../src/lib/seo/cover-constants.ts'

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

/**
 * Encodes a cover to AVIF, upscaling to the Google Discover minimum width
 * (aspect ratio preserved) when the source is too narrow.
 */
async function encodeCoverAvif(input: Buffer | string): Promise<Buffer> {
  let pipeline = sharp(input)
  const metadata = await pipeline.metadata()
  if ((metadata.width ?? 0) < MIN_COVER_IMAGE_WIDTH) {
    console.warn(
      `Cover source is ${metadata.width}px wide; upscaling to ${MIN_COVER_IMAGE_WIDTH}px (Google Discover minimum). Prefer sources at least ${MIN_COVER_IMAGE_WIDTH}px wide.`,
    )
    pipeline = pipeline.resize({ width: MIN_COVER_IMAGE_WIDTH })
  }

  return pipeline
    .avif({ quality: 82, effort: 4, chromaSubsampling: '4:4:4' })
    .toBuffer()
}

async function writeAvifFromPng(outputDir: string, png: Uint8Array): Promise<void> {
  writeFileSync(join(outputDir, 'cover-source.png'), png)

  const avif = await encodeCoverAvif(Buffer.from(png))

  writeFileSync(join(outputDir, 'cover.avif'), avif)
  console.log(`Wrote cover-source.png and cover.avif (${avif.length} bytes)`)
}

async function convertCoverSourceToAvif(outputDir: string): Promise<void> {
  mkdirSync(outputDir, { recursive: true })

  const sourcePath = join(outputDir, 'cover-source.png')
  if (!(await exists(sourcePath))) {
    throw new Error(`Missing cover source: ${sourcePath}`)
  }

  const avif = await encodeCoverAvif(sourcePath)

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

/**
 * Converts every `<name>-source.png` screenshot in the slug directory to
 * `<name>.avif` (resized to fit 1280px, matching imported blog images).
 */
async function convertScreenshotSources(outputDir: string): Promise<void> {
  const { readdirSync } = await import('node:fs')
  for (const name of readdirSync(outputDir)) {
    if (!name.endsWith('-source.png') || name === 'cover-source.png') continue
    const avif = await sharp(join(outputDir, name))
      .resize({ width: 1280, height: 1280, fit: 'inside', withoutEnlargement: true })
      .avif({ quality: 82, effort: 4, chromaSubsampling: '4:4:4' })
      .toBuffer()
    const outName = name.replace(/-source\.png$/, '.avif')
    writeFileSync(join(outputDir, outName), avif)
    console.log(`Wrote ${outName} (${avif.length} bytes)`)
  }
}

async function generateMcpServerOauth2Images(outputDir: string): Promise<void> {
  mkdirSync(outputDir, { recursive: true })

  const { width, height } = resolveCoverSizePresetKey('blog')

  const data: CoverRenderData = {
    template: 'simple-title',
    theme: 'dark',
    format: 'png',
    width,
    height,
    title: 'Turn your app into an MCP server',
    subtitle: 'A remote MCP server on Appwrite Functions, secured by your OAuth2 server',
    eyebrow: 'Tutorial',
  }

  const png = await renderCoverImage(data)
  await writeAvifFromPng(outputDir, png)
  await convertScreenshotSources(outputDir)
}

async function generateAnnouncingAppwrite2Cover(
  outputDir: string,
): Promise<void> {
  mkdirSync(outputDir, { recursive: true })

  const { width, height } = resolveCoverSizePresetKey('blog')

  const data: CoverRenderData = {
    template: 'version-title',
    theme: 'dark',
    format: 'png',
    width,
    height,
    version: '2.0',
    title: 'A new foundation for your apps',
    eyebrow: 'Announcing Appwrite',
  }

  const png = await renderCoverImage(data)
  await writeAvifFromPng(outputDir, png)
}

async function generateAnnouncingGitlabSupportCover(
  outputDir: string,
): Promise<void> {
  mkdirSync(outputDir, { recursive: true })

  const { width, height } = resolveCoverSizePresetKey('blog')

  const data: CoverRenderData = {
    template: 'integration',
    theme: 'dark',
    format: 'png',
    width,
    height,
    title: 'GitLab comes to Appwrite',
    subtitle: 'Deploy Sites and Functions from your GitLab groups and projects',
    logoLeft: '/icons/appwrite.svg',
    logoRight: '/icons/gitlab.svg',
    connector: '×',
  }

  const png = await renderCoverImage(data)
  await writeAvifFromPng(outputDir, png)
}

async function generateAnnouncingBitbucketSupportCover(
  outputDir: string,
): Promise<void> {
  mkdirSync(outputDir, { recursive: true })

  const { width, height } = resolveCoverSizePresetKey('blog')

  const data: CoverRenderData = {
    template: 'integration',
    theme: 'dark',
    format: 'png',
    width,
    height,
    title: 'Bitbucket comes to Appwrite',
    subtitle: 'Deploy Sites and Functions from your Bitbucket workspaces',
    logoLeft: '/icons/appwrite.svg',
    logoRight: '/icons/bitbucket.svg',
    connector: '×',
  }

  const png = await renderCoverImage(data)
  await writeAvifFromPng(outputDir, png)
}

/**
 * Screenshot template with the Formwrite landing page. The screenshot ships as
 * `formwrite-landing.avif` (encoded from `formwrite-landing-source.png` when present).
 */
async function generateMultiTenantAppwriteTeamsCover(outputDir: string): Promise<void> {
  mkdirSync(outputDir, { recursive: true })
  // Inline screenshots first; the landing shot is re-encoded at full size below.
  await convertScreenshotSources(outputDir)
  const { existsSync, readFileSync } = await import('node:fs')
  const sourcePng = join(outputDir, 'formwrite-landing-source.png')
  const shotAvif = join(outputDir, 'formwrite-landing.avif')
  if (existsSync(sourcePng)) {
    const avif = await sharp(sourcePng)
      .avif({ quality: 82, effort: 4, chromaSubsampling: '4:4:4' })
      .toBuffer()
    writeFileSync(shotAvif, avif)
    console.log(`Wrote formwrite-landing.avif (${avif.length} bytes)`)
  }
  if (!(await exists(shotAvif))) {
    throw new Error(`Missing screenshot: ${shotAvif}`)
  }
  const screenshot = `data:image/avif;base64,${readFileSync(shotAvif).toString('base64')}`

  const { width, height } = resolveCoverSizePresetKey('blog')
  const data: CoverRenderData = {
    template: 'screenshot',
    theme: 'dark',
    format: 'png',
    width,
    height,
    title: 'Multi-tenant SaaS with Appwrite Teams',
    subtitle: 'How Formwrite isolates every workspace with Teams and stores form data in DocumentsDB',
    screenshot,
    zoom: 1,
    focusX: 0,
    focusY: 0,
    frameWidthPercent: 82,
    frameHeightPercent: 100,
  }
  const png = await renderCoverImage(data)
  await writeAvifFromPng(outputDir, png)
}

const IMAGE_GENERATORS: Record<string, (outputDir: string) => Promise<void>> = {
  'multi-tenant-app-appwrite-teams-documentsdb': generateMultiTenantAppwriteTeamsCover,
  'announcing-console-terminal': convertCoverSourceToAvif,
  'announcing-appwrite-explorer': generateAnnouncingAppwriteExplorerCover,
  'announcing-appwrite-domains': convertCoverSourceToAvif,
  'announcing-gitlab-support': generateAnnouncingGitlabSupportCover,
  'announcing-bitbucket-support': generateAnnouncingBitbucketSupportCover,
  'turn-your-app-into-an-mcp-server': generateMcpServerOauth2Images,
  'announcing-appwrite-2': generateAnnouncingAppwrite2Cover,
  'announcing-console-iv': convertCoverSourceToAvif,
}

async function main() {
  const slug = process.argv[2] ?? 'announcing-console-terminal'
  const generate = IMAGE_GENERATORS[slug]
  if (!generate) {
    console.error(`Unknown blog slug: ${slug}`)
    console.error(`Available: ${Object.keys(IMAGE_GENERATORS).join(', ')}`)
    process.exit(1)
  }

  const outputDir = join(VIBES_ROOT, 'public', 'images', 'blog', slug)
  console.log(`Generating cover for ${slug}...`)
  await generate(outputDir)
  console.log(`Done. Cover saved to public/images/blog/${slug}/`)
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
