/**
 * Imports integrations catalog content from the Appwrite website repo.
 * Copies markdoc plus only images referenced in content (not decorative site assets).
 * Run: bun run import:integrations
 */
import { cp, mkdir, readdir, readFile, rm, stat } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { convertImagesToAvif } from './lib/convert-images-to-avif.ts'
import { removeImportedContentSvgs } from './lib/remove-content-svgs.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = resolve(__dirname, '..')
const WEBSITE_ROOT = resolve(VIBES_ROOT, '..', 'website')
const INTEGRATIONS_SRC = join(WEBSITE_ROOT, 'src', 'routes', 'integrations')
const INTEGRATIONS_DEST = join(VIBES_ROOT, 'src', 'content', 'integrations')
const PUBLIC_INTEGRATIONS_IMAGES = join(VIBES_ROOT, 'public', 'images', 'integrations')
const WEBSITE_INTEGRATIONS_IMAGES = join(
  WEBSITE_ROOT,
  'static',
  'images',
  'integrations',
)

const CONTENT_SCAN_ROOTS = [
  join(VIBES_ROOT, 'src', 'content', 'integrations'),
  join(VIBES_ROOT, 'src', 'content', 'blog', 'posts'),
]

const INTEGRATION_IMAGE_PATH_RE = /\/images\/integrations\/[^\s"'`)]+/g

/** Paths we never import (old marketing/decorative assets or replaced by /public/icons). */
const SKIP_IMAGE_PREFIXES = ['/images/integrations/avatars/']

async function exists(path: string): Promise<boolean> {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

async function copyIntegrationMarkdocEntries(): Promise<string[]> {
  const copied: string[] = []
  if (!(await exists(INTEGRATIONS_SRC))) return copied

  await mkdir(INTEGRATIONS_DEST, { recursive: true })

  const entries = await readdir(INTEGRATIONS_SRC, { withFileTypes: true })
  for (const entry of entries) {
    if (!entry.isDirectory()) continue
    if (entry.name.startsWith('+') || entry.name.startsWith('[')) continue

    const markdocSrc = join(INTEGRATIONS_SRC, entry.name, '+page.markdoc')
    if (!(await exists(markdocSrc))) continue

    await cp(markdocSrc, join(INTEGRATIONS_DEST, `${entry.name}.markdoc`))
    copied.push(entry.name)
  }

  return copied.sort()
}

async function collectReferencedIntegrationImages(): Promise<Set<string>> {
  const referenced = new Set<string>()

  for (const root of CONTENT_SCAN_ROOTS) {
    if (!(await exists(root))) continue

    for (const filename of await readdir(root)) {
      if (!filename.endsWith('.markdoc')) continue

      const raw = await readFile(join(root, filename), 'utf8')
      for (const match of raw.matchAll(INTEGRATION_IMAGE_PATH_RE)) {
        const path = match[0].replace(/['"]+$/, '')
        if (SKIP_IMAGE_PREFIXES.some((prefix) => path.startsWith(prefix))) continue
        referenced.add(path)
      }
    }
  }

  return referenced
}

async function copyReferencedIntegrationImages(
  referenced: Set<string>,
): Promise<{ copied: number; missing: number }> {
  await rm(PUBLIC_INTEGRATIONS_IMAGES, { recursive: true, force: true })

  let copied = 0
  let missing = 0

  for (const webPath of [...referenced].sort()) {
    const relativePath = webPath.replace(/^\/images\/integrations\//, '')
    const src = join(WEBSITE_INTEGRATIONS_IMAGES, relativePath)
    const dest = join(PUBLIC_INTEGRATIONS_IMAGES, relativePath)

    if (!(await exists(src))) {
      missing++
      console.warn(`Missing integration image source: ${src}`)
      continue
    }

    await mkdir(dirname(dest), { recursive: true })
    await cp(src, dest)
    copied++
  }

  return { copied, missing }
}

async function main() {
  if (!(await exists(INTEGRATIONS_SRC))) {
    console.error(`Website integrations not found at ${INTEGRATIONS_SRC}`)
    process.exit(1)
  }

  console.log('Importing integrations catalog from website...')
  await rm(INTEGRATIONS_DEST, { recursive: true, force: true })

  const integrations = await copyIntegrationMarkdocEntries()
  const referenced = await collectReferencedIntegrationImages()
  const { copied, missing } = await copyReferencedIntegrationImages(referenced)

  console.log(
    `Imported ${copied} referenced integration ${copied === 1 ? 'image' : 'images'} (${referenced.size} unique paths)`,
  )
  if (missing > 0) {
    console.warn(`${missing} referenced ${missing === 1 ? 'image was' : 'images were'} missing in website repo`)
  }

  const svgsRemoved = await removeImportedContentSvgs(['integrations'])
  if (svgsRemoved > 0) {
    console.log(`Removed ${svgsRemoved} SVG file(s) from imported integration images`)
  }

  const { converted, referencesUpdated } = await convertImagesToAvif({
    sections: ['integrations'],
  })
  if (converted > 0 || referencesUpdated > 0) {
    console.log(
      `Converted ${converted} ${converted === 1 ? 'image' : 'images'} to AVIF, updated ${referencesUpdated} content file(s)`,
    )
  }

  console.log(`Imported ${integrations.length} integrations`)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
