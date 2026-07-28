/**
 * Imports docs content from the Appwrite website repo into src/content/docs.
 * Vibes-native docs live in src/content/docs-local and are not touched by this script.
 * Run: bun run import:docs
 */
import { cp, mkdir, readdir, rm, stat } from 'node:fs/promises'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { convertImagesToAvif } from './lib/convert-images-to-avif.ts'
import { copyContentImagesFromWebsite } from './lib/copy-content-images.ts'
import { removeImportedContentSvgs } from './lib/remove-content-svgs.ts'
import { rewriteImportedDocsCardIcons } from './lib/rewrite-markdoc-card-icons.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = resolve(__dirname, '..')
const WEBSITE_ROOT = resolve(VIBES_ROOT, '..', 'website')
const DOCS_SRC = join(WEBSITE_ROOT, 'src', 'routes', 'docs')
const PARTIALS_SRC = join(WEBSITE_ROOT, 'src', 'partials')
const DOCS_DEST = join(VIBES_ROOT, 'src', 'content', 'docs')
const PARTIALS_DEST = join(VIBES_ROOT, 'src', 'content', 'docs-partials')

async function exists(path: string): Promise<boolean> {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

async function copyMarkdocFiles(dir: string, destRoot: string): Promise<string[]> {
  const copied: string[] = []
  const entries = await readdir(dir, { withFileTypes: true })

  for (const entry of entries) {
    const srcPath = join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name.startsWith('[')) continue
      copied.push(...(await copyMarkdocFiles(srcPath, destRoot)))
      continue
    }

    if (entry.name !== '+page.markdoc') continue

    // SvelteKit route groups like (overview) don't appear in URLs; strip them
    // so e.g. products/databases/(overview)/+page.markdoc becomes the
    // products/databases index page.
    const relDir = relative(DOCS_SRC, dir)
      .split(sep)
      .filter((segment) => !/^\(.+\)$/.test(segment))
      .join(sep)
    const destDir = join(destRoot, relDir)
    await mkdir(destDir, { recursive: true })
    const destPath = join(destDir, 'index.markdoc')
    await cp(srcPath, destPath)
    copied.push(relDir === '' ? 'index' : relDir.replace(/\\/g, '/'))
  }

  return copied
}

async function copyPartials(): Promise<number> {
  if (!(await exists(PARTIALS_SRC))) return 0
  await rm(PARTIALS_DEST, { recursive: true, force: true })
  await cp(PARTIALS_SRC, PARTIALS_DEST, { recursive: true })
  const files = await readdir(PARTIALS_DEST)
  return files.filter((f) => f.endsWith('.md')).length
}

async function copyPromptFiles(): Promise<number> {
  let count = 0
  const quickStartsDir = join(DOCS_SRC, 'quick-starts')
  if (!(await exists(quickStartsDir))) return 0

  const frameworks = await readdir(quickStartsDir, { withFileTypes: true })
  for (const fw of frameworks) {
    if (!fw.isDirectory()) continue
    const promptSrc = join(quickStartsDir, fw.name, 'prompt.md')
    if (!(await exists(promptSrc))) continue
    const destDir = join(DOCS_DEST, 'quick-starts', fw.name)
    await mkdir(destDir, { recursive: true })
    await cp(promptSrc, join(destDir, 'prompt.md'))
    count++
  }
  return count
}

async function main() {
  if (!(await exists(DOCS_SRC))) {
    console.error(`Website docs not found at ${DOCS_SRC}`)
    process.exit(1)
  }

  console.log('Importing docs from website...')
  await rm(DOCS_DEST, { recursive: true, force: true })
  await mkdir(DOCS_DEST, { recursive: true })

  const pages = await copyMarkdocFiles(DOCS_SRC, DOCS_DEST)
  const partialCount = await copyPartials()
  const promptCount = await copyPromptFiles()

  const iconsRewritten = await rewriteImportedDocsCardIcons(DOCS_DEST, PARTIALS_DEST)
  if (iconsRewritten > 0) {
    console.log(
      `Rewrote ${iconsRewritten} docs file(s) to use icon= instead of website SVG image paths`,
    )
  }

  const imageResults = await copyContentImagesFromWebsite(['docs', 'changelog'])
  for (const { section, copied } of imageResults) {
    console.log(
      copied
        ? `Imported images for ${section}`
        : `Skipped missing ${section} images`,
    )
  }

  const svgsRemoved = await removeImportedContentSvgs(['docs', 'changelog'])
  if (svgsRemoved > 0) {
    console.log(`Removed ${svgsRemoved} SVG file(s) from imported content images`)
  }

  const { converted, referencesUpdated } = await convertImagesToAvif({
    sections: ['docs', 'changelog'],
  })
  if (converted > 0 || referencesUpdated > 0) {
    console.log(
      `Converted ${converted} ${converted === 1 ? 'image' : 'images'} to AVIF, updated ${referencesUpdated} content file(s)`,
    )
  }

  console.log(`Imported ${pages.length} doc pages`)
  console.log(`Imported ${partialCount} partials`)
  console.log(`Imported ${promptCount} quick-start prompts`)

  // Appwrite Firewall replaced the retired Network WAF product page.
  const retiredNetworkWaf = join(DOCS_DEST, 'products', 'network', 'waf')
  await rm(retiredNetworkWaf, { recursive: true, force: true })
  console.log('Removed retired Network WAF docs (products/network/waf)')
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
