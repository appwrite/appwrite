/**
 * Imports changelog entries from the Appwrite website repo into
 * src/content/changelog. Vibes-native entries live in src/content/changelog-local
 * and are not touched by this script.
 * Run: bun run import:changelog
 */
import { cp, mkdir, readdir, stat } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { convertImagesToAvif } from './lib/convert-images-to-avif.ts'
import { copyContentImagesFromWebsite } from './lib/copy-content-images.ts'
import { removeImportedContentSvgs } from './lib/remove-content-svgs.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = resolve(__dirname, '..')
const WEBSITE_ROOT = resolve(VIBES_ROOT, '..', 'website')
const CHANGELOG_ROOT = join(WEBSITE_ROOT, 'src', 'routes', 'changelog')
const CHANGELOG_DEST = join(VIBES_ROOT, 'src', 'content', 'changelog', 'entries')

async function exists(path: string): Promise<boolean> {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

/** Website changelog entries are flat `.markdoc` files in a SvelteKit route group. */
async function findChangelogEntriesDir(dir: string): Promise<string | null> {
  if (!(await exists(dir))) return null

  const entries = await readdir(dir, { withFileTypes: true })
  const markdocCount = entries.filter(
    (entry) => entry.isFile() && entry.name.endsWith('.markdoc'),
  ).length
  if (markdocCount > 0) return dir

  for (const entry of entries) {
    if (!entry.isDirectory()) continue
    if (entry.name.startsWith('+') || entry.name.startsWith('[')) continue
    const found = await findChangelogEntriesDir(join(dir, entry.name))
    if (found) return found
  }

  return null
}

async function copyChangelogEntries(
  srcDir: string,
  destDir: string,
): Promise<string[]> {
  const copied: string[] = []
  await mkdir(destDir, { recursive: true })

  const entries = await readdir(srcDir, { withFileTypes: true })
  for (const entry of entries) {
    if (!entry.isFile() || !entry.name.endsWith('.markdoc')) continue
    await cp(join(srcDir, entry.name), join(destDir, entry.name))
    copied.push(entry.name.replace(/\.markdoc$/, ''))
  }

  return copied.sort()
}

async function main() {
  const changelogSrc = await findChangelogEntriesDir(CHANGELOG_ROOT)
  if (!changelogSrc) {
    console.error(`Website changelog not found under ${CHANGELOG_ROOT}`)
    process.exit(1)
  }

  console.log(`Importing changelog entries from ${changelogSrc}...`)
  await mkdir(CHANGELOG_DEST, { recursive: true })

  const entries = await copyChangelogEntries(changelogSrc, CHANGELOG_DEST)

  const imageResults = await copyContentImagesFromWebsite(['changelog'])
  for (const { section, copied } of imageResults) {
    console.log(
      copied
        ? `Imported images for ${section}`
        : `Skipped missing ${section} images`,
    )
  }

  const svgsRemoved = await removeImportedContentSvgs(['changelog'])
  if (svgsRemoved > 0) {
    console.log(`Removed ${svgsRemoved} SVG file(s) from imported content images`)
  }

  const { converted, referencesUpdated } = await convertImagesToAvif({
    sections: ['changelog'],
  })
  if (converted > 0 || referencesUpdated > 0) {
    console.log(
      `Converted ${converted} ${converted === 1 ? 'image' : 'images'} to AVIF, updated ${referencesUpdated} content file(s)`,
    )
  }

  console.log(`Imported ${entries.length} changelog entries`)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
