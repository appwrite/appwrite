/**
 * Imports blog content from the Appwrite website repo into src/content/blog.
 * Vibes-native posts live in src/content/blog-local and are not touched by this script.
 * Run: bun run import:blog
 */
import { cp, mkdir, readdir, rm, stat } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { convertImagesToAvif } from './lib/convert-images-to-avif.ts'
import { copyContentImagesFromWebsite } from './lib/copy-content-images.ts'
import { removeImportedContentSvgs } from './lib/remove-content-svgs.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = resolve(__dirname, '..')
const WEBSITE_ROOT = resolve(VIBES_ROOT, '..', 'website')
const BLOG_SRC = join(WEBSITE_ROOT, 'src', 'routes', 'blog')
const BLOG_DEST = join(VIBES_ROOT, 'src', 'content', 'blog')

async function exists(path: string): Promise<boolean> {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

async function copyMarkdocEntries(
  srcDir: string,
  destDir: string,
): Promise<string[]> {
  const copied: string[] = []
  if (!(await exists(srcDir))) return copied

  const entries = await readdir(srcDir, { withFileTypes: true })
  for (const entry of entries) {
    if (!entry.isDirectory()) continue
    if (entry.name.startsWith('+') || entry.name.startsWith('[')) continue

    const markdocSrc = join(srcDir, entry.name, '+page.markdoc')
    if (!(await exists(markdocSrc))) continue

    await mkdir(destDir, { recursive: true })
    await cp(markdocSrc, join(destDir, `${entry.name}.markdoc`))
    copied.push(entry.name)
  }

  return copied.sort()
}

async function main() {
  if (!(await exists(BLOG_SRC))) {
    console.error(`Website blog not found at ${BLOG_SRC}`)
    process.exit(1)
  }

  console.log('Importing blog content from website...')
  await rm(BLOG_DEST, { recursive: true, force: true })

  const posts = await copyMarkdocEntries(
    join(BLOG_SRC, 'post'),
    join(BLOG_DEST, 'posts'),
  )
  const categories = await copyMarkdocEntries(
    join(BLOG_SRC, 'category'),
    join(BLOG_DEST, 'categories'),
  )
  const authors = await copyMarkdocEntries(
    join(BLOG_SRC, 'author'),
    join(BLOG_DEST, 'authors'),
  )

  const imageResults = await copyContentImagesFromWebsite(['blog', 'avatars'])
  for (const { section, copied } of imageResults) {
    console.log(
      copied
        ? `Imported images for ${section}`
        : `Skipped missing ${section} images`,
    )
  }

  const svgsRemoved = await removeImportedContentSvgs(['blog', 'avatars'])
  if (svgsRemoved > 0) {
    console.log(`Removed ${svgsRemoved} SVG file(s) from imported content images`)
  }

  const { converted, referencesUpdated } = await convertImagesToAvif({
    sections: ['blog', 'avatars'],
  })
  if (converted > 0 || referencesUpdated > 0) {
    console.log(
      `Converted ${converted} ${converted === 1 ? 'image' : 'images'} to AVIF, updated ${referencesUpdated} content file(s)`,
    )
  }

  console.log(`Imported ${posts.length} posts`)
  console.log(`Imported ${categories.length} categories`)
  console.log(`Imported ${authors.length} authors`)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
