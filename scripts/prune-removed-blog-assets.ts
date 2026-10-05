/**
 * Deletes images that only removed blog posts (`removed: true`) use from the
 * build output, so they are not served or published to the CDN. It only ever
 * deletes inside dist/client: source images under public/ are never touched.
 * Images that live content still references (e.g. a changelog entry reusing a
 * removed post's cover) are kept.
 *
 * Runs after `vite build`, which copies public/ into dist/client.
 * Run: bun run prune:removed-blog-assets
 */
import { existsSync, readFileSync, readdirSync, rmSync, rmdirSync, statSync } from 'node:fs'
import { dirname, join, resolve, sep } from 'node:path'
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

export type PruneRemovedBlogAssetsOptions = {
  /** Build output to prune (dist/client). Nothing outside it is deleted. */
  clientDir: string
  /** Source public/ directory, read to find each removed post's image folder. */
  publicDir: string
  /** Markdoc source of each removed post, keyed by slug. */
  removedPosts: ReadonlyMap<string, string>
  /** Text of every live content and code file. */
  liveSources: Iterable<string>
}

export function findImagePaths(text: string): string[] {
  return [...text.matchAll(IMAGE_PATH_PATTERN)].map((match) => match[0])
}

function listPublicFiles(publicDir: string, publicPath: string): string[] {
  const directory = join(publicDir, publicPath)
  if (!existsSync(directory) || !statSync(directory).isDirectory()) return []

  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const entryPath = `${publicPath}/${entry.name}`
    if (entry.isDirectory()) return listPublicFiles(publicDir, entryPath)
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

/** Resolves a public image path inside `clientDir`, or null if it would escape it. */
function resolveOutputPath(clientDir: string, imagePath: string): string | null {
  const root = resolve(clientDir)
  const outputPath = resolve(root, `.${imagePath}`)
  return outputPath.startsWith(root + sep) ? outputPath : null
}

/** Deletes removed-only images from `clientDir`. Returns the pruned and kept image paths. */
export function pruneRemovedBlogAssets({
  clientDir,
  publicDir,
  removedPosts,
  liveSources,
}: PruneRemovedBlogAssetsOptions): { pruned: string[]; kept: string[] } {
  const liveImagePaths = new Set<string>()
  for (const source of liveSources) {
    for (const imagePath of findImagePaths(source)) liveImagePaths.add(imagePath)
  }

  const candidates = new Set<string>()
  for (const [slug, source] of removedPosts) {
    for (const imagePath of findImagePaths(source)) candidates.add(imagePath)
    for (const imagePath of listPublicFiles(publicDir, `/images/blog/${slug}`)) {
      candidates.add(imagePath)
    }
  }

  const pruned: string[] = []
  const kept: string[] = []
  for (const imagePath of [...candidates].sort()) {
    if (liveImagePaths.has(imagePath)) {
      kept.push(imagePath)
      continue
    }
    const outputPath = resolveOutputPath(clientDir, imagePath)
    if (!outputPath || !existsSync(outputPath) || !statSync(outputPath).isFile()) continue
    rmSync(outputPath)
    pruned.push(imagePath)
  }

  for (const slug of removedPosts.keys()) {
    removeEmptyDirectories(join(clientDir, 'images', 'blog', slug))
  }

  return { pruned, kept }
}

function main() {
  if (!existsSync(CLIENT_DIR)) {
    console.log('No dist/client build output; nothing to prune.')
    return
  }

  const removedSlugs = readRemovedBlogPostSlugs()
  if (removedSlugs.size === 0) return

  const { pruned, kept } = pruneRemovedBlogAssets({
    clientDir: CLIENT_DIR,
    publicDir: PUBLIC_DIR,
    removedPosts: new Map(
      [...removedSlugs].map((slug) => [
        slug,
        readFileSync(getBlogPostSourceFile(slug), 'utf8'),
      ]),
    ),
    liveSources: listLiveSourceFiles(removedSlugs).map((file) =>
      readFileSync(file, 'utf8'),
    ),
  })

  console.log(
    `Pruned ${pruned.length} images of ${removedSlugs.size} removed blog posts from dist/client (${kept.length} kept, still referenced by live content)`,
  )
}

if (import.meta.main) {
  main()
}
