/**
 * Generates docs manifest from imported markdoc content.
 * Invoked by generate:docs (scripts/generate-docs.ts).
 */
import { readdir, readFile, writeFile, mkdir } from 'node:fs/promises'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import matter from 'gray-matter'
import { getDocsPageBreadcrumbs } from '../../src/lib/docs/breadcrumbs.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '../..')
const DOCS_DIR = join(VIBES_ROOT, 'src', 'content', 'docs')
const DOCS_LOCAL_DIR = join(VIBES_ROOT, 'src', 'content', 'docs-local')
const OUTPUT_DIR = join(VIBES_ROOT, 'src', 'lib', 'docs', 'generated')

const WORDS_PER_MINUTE = 200
const SEARCH_EXCERPT_MAX_WORDS = 80

type DocsPageEntry = {
  slug: string
  title: string
  description: string
  layout: string
  readingTimeMinutes: number
  step?: number
  category?: string
  framework?: string
  draft?: boolean
}

type DocsSearchEntry = {
  slug: string
  title: string
  description: string
  excerpt: string
  breadcrumbs: string[]
}

async function walkMarkdocFiles(dir: string): Promise<string[]> {
  const files: string[] = []
  const entries = await readdir(dir, { withFileTypes: true })

  for (const entry of entries) {
    const fullPath = join(dir, entry.name)
    if (entry.isDirectory()) {
      files.push(...(await walkMarkdocFiles(fullPath)))
    } else if (entry.name === 'index.markdoc') {
      files.push(fullPath)
    }
  }

  return files
}

function slugFromPath(filePath: string, baseDir: string): string {
  const rel = relative(baseDir, dirname(filePath))
  return rel === '' ? '' : rel.replace(/\\/g, '/')
}

function stripMarkdocText(text: string): string {
  let out = text.replace(/^---[\s\S]*?---\s*/m, '')
  out = out.replace(/```[\s\S]*?```/g, '')
  out = out.replace(/`[^`]*`/g, '')
  out = out.replace(/!\[[^\]]*\]\([^)]*\)/g, '')
  out = out.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
  out = out.replace(/\{%[\s\S]*?%\}/g, '')
  out = out.replace(/<[^>]+>/g, '')
  out = out.replace(/^#{1,6}\s+/gm, '')
  return out.replace(/[\t ]+/g, ' ').trim()
}

function toSummary(text: string, maxWords = 18): string {
  const words = stripMarkdocText(text).split(/\s+/).filter(Boolean)
  if (words.length <= maxWords) return words.join(' ')
  return words.slice(0, maxWords).join(' ') + '…'
}

function toExcerpt(text: string, maxWords = SEARCH_EXCERPT_MAX_WORDS): string {
  const words = stripMarkdocText(text).split(/\s+/).filter(Boolean)
  if (words.length <= maxWords) return words.join(' ')
  return words.slice(0, maxWords).join(' ') + '…'
}

function getReadingTimeMinutes(text: string): number {
  const words = stripMarkdocText(text).split(/\s+/).filter(Boolean).length
  return Math.max(1, Math.ceil(words / WORDS_PER_MINUTE))
}

async function main() {
  const importedFiles = await walkMarkdocFiles(DOCS_DIR)
  let localFiles: string[] = []
  try {
    localFiles = await walkMarkdocFiles(DOCS_LOCAL_DIR)
  } catch {
    localFiles = []
  }

  const filesBySlug = new Map<string, string>()
  for (const filePath of importedFiles) {
    filesBySlug.set(slugFromPath(filePath, DOCS_DIR), filePath)
  }
  for (const filePath of localFiles) {
    filesBySlug.set(slugFromPath(filePath, DOCS_LOCAL_DIR), filePath)
  }

  const pages: DocsPageEntry[] = []
  const searchIndex: DocsSearchEntry[] = []

  for (const filePath of filesBySlug.values()) {
    const raw = await readFile(filePath, 'utf-8')
    const { data, content } = matter(raw)
    const relToRoot = relative(VIBES_ROOT, filePath).replace(/\\/g, '/')
    const baseDir = relToRoot.startsWith('src/content/docs-local/')
      ? DOCS_LOCAL_DIR
      : DOCS_DIR
    const slug = slugFromPath(filePath, baseDir)

    const title =
      (typeof data.title === 'string' && data.title) ||
      content.match(/^#\s+(.+)$/m)?.[1]?.replace(/\s*\{%[^%]*%\}/g, '').trim() ||
      slug.split('/').pop()?.replace(/-/g, ' ') ||
      'Untitled'

    const description =
      (typeof data.description === 'string' && data.description) || toSummary(raw)

    pages.push({
      slug,
      title,
      description,
      layout: typeof data.layout === 'string' ? data.layout : 'article',
      readingTimeMinutes: getReadingTimeMinutes(raw),
      ...(typeof data.step === 'number' ? { step: data.step } : {}),
      ...(typeof data.category === 'string' ? { category: data.category.trim() } : {}),
      ...(typeof data.framework === 'string' ? { framework: data.framework.trim() } : {}),
      ...(data.draft === true ? { draft: true } : {}),
    })

    searchIndex.push({
      slug,
      title,
      description,
      excerpt: toExcerpt(raw),
      breadcrumbs: [],
    })
  }

  pages.sort((a, b) => a.slug.localeCompare(b.slug))
  searchIndex.sort((a, b) => a.slug.localeCompare(b.slug))

  const pageMap = Object.fromEntries(pages.map((page) => [page.slug, page]))

  for (const entry of searchIndex) {
    entry.breadcrumbs = getDocsPageBreadcrumbs(entry.slug, pageMap)
  }

  await mkdir(OUTPUT_DIR, { recursive: true })

  const manifestContent = `// Auto-generated by generate:docs - do not edit manually.
import type { DocsPageMeta } from '../types'

export const DOCS_PAGES: DocsPageMeta[] = ${JSON.stringify(pages, null, 2)} as DocsPageMeta[]

export const DOCS_PAGE_MAP: Record<string, DocsPageMeta> = Object.fromEntries(
  DOCS_PAGES.map((page) => [page.slug, page]),
)

export const DOCS_SLUGS = DOCS_PAGES.map((page) => page.slug)
`

  const searchIndexContent = `// Auto-generated by generate:docs - do not edit manually.
import type { DocsSearchEntry } from '../search'

export const DOCS_SEARCH_INDEX: DocsSearchEntry[] = ${JSON.stringify(searchIndex, null, 2)} as DocsSearchEntry[]
`

  await writeFile(join(OUTPUT_DIR, 'manifest.ts'), manifestContent, 'utf-8')
  await writeFile(join(OUTPUT_DIR, 'search-index.ts'), searchIndexContent, 'utf-8')
  console.log(`Generated manifest with ${pages.length} pages`)
  console.log(`Generated search index with ${searchIndex.length} entries`)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
