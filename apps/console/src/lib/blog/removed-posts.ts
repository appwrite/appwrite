/**
 * Node-only helpers for blog posts marked `removed: true` (see
 * `isRemovedBlogPost`). Build scripts and lint use these; the runtime reads the
 * generated slug list in `./generated/removed` instead.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { isRemovedBlogPost, parseBlogFrontmatter } from './frontmatter'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..',
)

const srcDirectory = path.join(packageRoot, 'src')
const contentDirectory = path.join(srcDirectory, 'content')
const postsDirectory = path.join(contentDirectory, 'blog/posts')

/** Generated files that list removed slugs on purpose. */
const IGNORED_SOURCE_DIRECTORIES = [path.join(srcDirectory, 'lib/blog/generated')]
const LIVE_SOURCE_EXTENSIONS = new Set(['.markdoc', '.md', '.ts', '.tsx'])

const BLOG_POST_LINK_PATTERN = /\/blog\/post\/([A-Za-z0-9_.-]+)/g
/** Bare quoted slugs, e.g. `blogFooterLink(label, 'some-post')`. */
const QUOTED_SLUG_PATTERN = /(['"`])([A-Za-z0-9_.-]+)\1/g
const CODE_EXTENSIONS = new Set(['.ts', '.tsx'])

export type RemovedBlogPostLink = {
  line: number
  slug: string
}

/** Slugs of posts whose frontmatter has `removed: true`. */
export function readRemovedBlogPostSlugs(directory = postsDirectory): Set<string> {
  const slugs = new Set<string>()
  if (!fs.existsSync(directory)) return slugs

  for (const filename of fs.readdirSync(directory)) {
    if (!filename.endsWith('.markdoc')) continue
    const raw = fs.readFileSync(path.join(directory, filename), 'utf8')
    if (isRemovedBlogPost(parseBlogFrontmatter(raw).frontmatter)) {
      slugs.add(filename.replace(/\.markdoc$/, ''))
    }
  }

  return slugs
}

export function getBlogPostSourceFile(slug: string): string {
  return path.join(postsDirectory, `${slug}.markdoc`)
}

function walkFiles(directory: string, files: string[]) {
  if (IGNORED_SOURCE_DIRECTORIES.includes(directory)) return

  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const entryPath = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      walkFiles(entryPath, files)
    } else if (LIVE_SOURCE_EXTENSIONS.has(path.extname(entry.name))) {
      files.push(entryPath)
    }
  }
}

/**
 * Content and code files that still ship: everything under src/ except the
 * removed posts themselves and generated slug lists.
 */
export function listLiveSourceFiles(removedSlugs: ReadonlySet<string>): string[] {
  const removedFiles = new Set(
    [...removedSlugs].map((slug) => getBlogPostSourceFile(slug)),
  )
  const files: string[] = []
  walkFiles(srcDirectory, files)
  return files.filter((file) => !removedFiles.has(file)).sort()
}

function resolveRemovedSlug(
  candidate: string,
  removedSlugs: ReadonlySet<string>,
): string | null {
  if (removedSlugs.has(candidate)) return candidate
  // `/blog/post/<slug>.md` exports and links that end a sentence.
  const withoutExport = candidate.replace(/\.md$/, '')
  if (removedSlugs.has(withoutExport)) return withoutExport
  const withoutTrailingDots = candidate.replace(/\.+$/, '')
  if (removedSlugs.has(withoutTrailingDots)) return withoutTrailingDots
  return null
}

/**
 * Links (relative or absolute) to removed posts in `text`, with 1-based lines.
 * With `code: true`, also flags removed slugs passed as bare strings, since
 * code often builds the `/blog/post/` URL from a slug.
 */
export function findRemovedBlogPostLinks(
  text: string,
  removedSlugs: ReadonlySet<string>,
  options: { code?: boolean } = {},
): RemovedBlogPostLink[] {
  const links: RemovedBlogPostLink[] = []
  const lines = text.split('\n')

  for (const [index, line] of lines.entries()) {
    for (const match of line.matchAll(BLOG_POST_LINK_PATTERN)) {
      const slug = resolveRemovedSlug(match[1]!, removedSlugs)
      if (slug) links.push({ line: index + 1, slug })
    }
    if (!options.code) continue
    for (const match of line.matchAll(QUOTED_SLUG_PATTERN)) {
      if (removedSlugs.has(match[2]!)) links.push({ line: index + 1, slug: match[2]! })
    }
  }

  return links
}

/** Whether a source file is code, where bare slug strings also count as links. */
export function isCodeSourceFile(file: string): boolean {
  return CODE_EXTENSIONS.has(path.extname(file))
}
