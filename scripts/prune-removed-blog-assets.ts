/**
 * Deletes images that only removed blog posts (`removed: true`) use from the
 * build output, so they are not served or published to the CDN. Source files
 * under public/ stay put. Images that live content still references (e.g. a
 * changelog entry reusing a removed post's cover) are kept.
 *
 * Runs after `vite build`, which copies public/ into dist/client.
 * Run: bun run prune:removed-blog-assets
 */
import { existsSync, readFileSync, readdirSync, rmSync, rmdirSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  getBlogPostSourceFile,
  listLiveSourceFiles,
  readRemovedBlogPostSlugs,
} from '../src/lib/blog/removed-posts.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')
const PUBLIC_DIR = join(ROOT, 'public')
const CLIENT_DIR = join(ROOT, 'dist', 'client')

const IMAGE_PATH_PATTERN = /\/images\/[^\s"'`()<>[\]{}?#]+/g

export function findImagePaths(text: string): string[] {
  return [...text.matchAll(IMAGE_PATH_PATTERN)].map((match) => match[0])
}

function listPublicFiles(publicPath: string): string[] {
  const directory = join(PUBLIC_DIR, publicPath)
  if (!existsSync(directory) || !statSync(directory).isDirectory()) return []

  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const entryPath = `${publicPath}/${entry.name}`
    if (entry.isDirectory()) return listPublicFiles(entryPath)
    return entry.isFile() ? [entryPath] : []
  })
}

function removeEmptyDirectories(directory: string) {
  if (!existsSync(directory)) return
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory()) removeEmptyDirectories(join(directory, entry.name))
  }
  if (readdirSync(directory).length === 0) rmdirSync(directory)
}

function main() {
  if (!existsSync(CLIENT_DIR)) {
    console.log('No dist/client build output; nothing to prune.')
    return
  }

  const removedSlugs = readRemovedBlogPostSlugs()
  if (removedSlugs.size === 0) return

  const liveImagePaths = new Set<string>()
  for (const file of listLiveSourceFiles(removedSlugs)) {
    for (const imagePath of findImagePaths(readFileSync(file, 'utf8'))) {
      liveImagePaths.add(imagePath)
    }
  }

  const candidates = new Set<string>()
  for (const slug of removedSlugs) {
    for (const imagePath of findImagePaths(readFileSync(getBlogPostSourceFile(slug), 'utf8'))) {
      candidates.add(imagePath)
    }
    for (const imagePath of listPublicFiles(`/images/blog/${slug}`)) {
      candidates.add(imagePath)
    }
  }

  let pruned = 0
  let kept = 0
  for (const imagePath of candidates) {
    if (liveImagePaths.has(imagePath)) {
      kept += 1
      continue
    }
    const outputPath = join(CLIENT_DIR, imagePath.slice(1))
    if (!existsSync(outputPath) || !statSync(outputPath).isFile()) continue
    rmSync(outputPath)
    pruned += 1
  }

  for (const slug of removedSlugs) {
    removeEmptyDirectories(join(CLIENT_DIR, 'images', 'blog', slug))
  }

  console.log(
    `Pruned ${pruned} images of ${removedSlugs.size} removed blog posts from dist/client (${kept} kept, still referenced by live content)`,
  )
}

if (import.meta.main) {
  main()
}
