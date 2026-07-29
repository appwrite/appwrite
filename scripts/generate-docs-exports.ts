/**
 * Generates static docs exports for SEO and LLM crawlers (llms.txt, llms-full.txt).
 * Sitemaps are generated via generate:sitemap.
 * Run manually when docs change: bun run generate:docs-exports
 */
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseBlogFrontmatter } from '../src/lib/blog/frontmatter'
import { parseChangelogFrontmatter } from '../src/lib/changelog/frontmatter'
import { DOCS_PAGES } from '../src/lib/docs/generated/manifest'
import { buildAppwriteLlmsTxt } from '../src/lib/seo/llms'
import type { LlmsContentMeta } from '../src/lib/seo/llms'
import { markdocToMarkdown } from '../src/lib/seo/markdoc-to-markdown'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '..')
const PUBLIC_DIR = join(VIBES_ROOT, 'public')
const DOCS_DIR = join(VIBES_ROOT, 'src', 'content', 'docs')
const BLOG_POSTS_DIRS = [
  join(VIBES_ROOT, 'src', 'content', 'blog', 'posts'),
  join(VIBES_ROOT, 'src', 'content', 'blog-local', 'posts'),
]
const CHANGELOG_DIR = join(VIBES_ROOT, 'src', 'content', 'changelog', 'entries')
const INTEGRATIONS_DIR = join(VIBES_ROOT, 'src', 'content', 'integrations')
const DOCS_PARTIALS_DIR = join(VIBES_ROOT, 'src', 'content', 'docs-partials')

const SITE_ORIGIN = process.env.VITE_SITE_ORIGIN ?? 'https://appwrite.io'

function stripFrontmatter(text: string): string {
  return text.replace(/^---[\s\S]*?---\s*/m, '')
}

function demoteHeadings(text: string, levels = 2): string {
  return text.replace(/^(#{1,6})\s/gm, (_, hashes: string) => {
    const next = Math.min(hashes.length + levels, 6)
    return '#'.repeat(next) + ' '
  })
}

function stripFirstH1(text: string): string {
  return text.replace(/^#\s+.+\n+/, '')
}

function parseBoolean(value: unknown): boolean {
  return value === true || value === 'true'
}

function asString(value: unknown): string | undefined {
  return typeof value === 'string' && value.trim() ? value : undefined
}

async function readMarkdocFiles(
  directory: string,
): Promise<{ slug: string; raw: string }[]> {
  if (!existsSync(directory)) return []

  const filenames = (await readdir(directory)).filter((filename) =>
    filename.endsWith('.markdoc'),
  )

  return Promise.all(
    filenames.map(async (filename) => ({
      slug: filename.replace(/\.markdoc$/, ''),
      raw: await readFile(join(directory, filename), 'utf-8'),
    })),
  )
}

/** Public blog posts (drafts and unlisted excluded), newest first. */
async function collectBlogMeta(): Promise<(LlmsContentMeta & { date: string })[]> {
  const postsBySlug = new Map<string, LlmsContentMeta & { date: string }>()

  for (const directory of BLOG_POSTS_DIRS) {
    for (const { slug, raw } of await readMarkdocFiles(directory)) {
      const { frontmatter } = parseBlogFrontmatter(raw)
      if (parseBoolean(frontmatter.draft) || parseBoolean(frontmatter.unlisted)) {
        postsBySlug.delete(slug)
        continue
      }

      postsBySlug.set(slug, {
        slug,
        title: asString(frontmatter.title) ?? slug,
        description: asString(frontmatter.description),
        date:
          frontmatter.date instanceof Date
            ? frontmatter.date.toISOString()
            : (asString(frontmatter.date) ?? ''),
      })
    }
  }

  return [...postsBySlug.values()].sort(
    (a, b) => new Date(b.date).getTime() - new Date(a.date).getTime(),
  )
}

/** Changelog entries, newest first (slugs are dates). */
async function collectChangelogMeta(): Promise<LlmsContentMeta[]> {
  const entries = (await readMarkdocFiles(CHANGELOG_DIR)).map(({ slug, raw }) => {
    const { frontmatter } = parseChangelogFrontmatter(raw)
    return {
      slug,
      title: asString(frontmatter.title) ?? slug,
      description: asString(frontmatter.description),
      date: asString(frontmatter.date) ?? slug,
    }
  })

  return entries.sort(
    (a, b) => new Date(b.date).getTime() - new Date(a.date).getTime(),
  )
}

/** Integrations sorted by title (matches runtime ordering). */
async function collectIntegrationsMeta(): Promise<LlmsContentMeta[]> {
  const integrations = (await readMarkdocFiles(INTEGRATIONS_DIR)).map(
    ({ slug, raw }) => {
      const { frontmatter } = parseBlogFrontmatter(raw)
      return {
        slug,
        title: asString(frontmatter.title) ?? slug,
        description: asString(frontmatter.description),
      }
    },
  )

  return integrations.sort((a, b) => a.title.localeCompare(b.title))
}

async function generateLlmsTxt(): Promise<string> {
  const [blog, changelog, integrations] = await Promise.all([
    collectBlogMeta(),
    collectChangelogMeta(),
    collectIntegrationsMeta(),
  ])

  return buildAppwriteLlmsTxt(
    {
      docs: DOCS_PAGES.map((page) => ({
        slug: page.slug,
        title: page.title,
        description: page.description,
      })),
      integrations,
      blog,
      changelog,
    },
    SITE_ORIGIN,
  )
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

function slugFromPath(filePath: string): string {
  const rel = relative(DOCS_DIR, dirname(filePath))
  return rel === '' ? '' : rel.replace(/\\/g, '/')
}

async function loadPartials(): Promise<Map<string, string>> {
  const partials = new Map<string, string>()
  if (!existsSync(DOCS_PARTIALS_DIR)) return partials

  for (const filename of await readdir(DOCS_PARTIALS_DIR)) {
    if (!filename.endsWith('.md')) continue
    partials.set(
      filename,
      await readFile(join(DOCS_PARTIALS_DIR, filename), 'utf-8'),
    )
  }

  return partials
}

function resolvePartials(content: string, partials: Map<string, string>): string {
  return content.replace(
    /\{%\s*partial\s+file="([^"]+)"\s*\/%\}/g,
    (_, fileName: string) => partials.get(fileName) ?? '',
  )
}

async function generateLlmsFullTxt(): Promise<string> {
  const files = await walkMarkdocFiles(DOCS_DIR)
  const partials = await loadPartials()
  const contentBySlug = new Map<string, string>()

  for (const filePath of files) {
    contentBySlug.set(slugFromPath(filePath), await readFile(filePath, 'utf-8'))
  }

  const sections = DOCS_PAGES.map((page) => {
    const raw = contentBySlug.get(page.slug)
    if (!raw) return null

    const href = page.slug ? `${SITE_ORIGIN}/docs/${page.slug}` : `${SITE_ORIGIN}/docs`
    let body = markdocToMarkdown(
      stripFrontmatter(resolvePartials(raw, partials)),
    )
    body = stripFirstH1(body)
    body = demoteHeadings(body)

    return `## ${page.title}\n\nURL: ${href}\n\n${body.trim()}`
  }).filter(Boolean)

  return sections.join('\n\n---\n\n') + '\n'
}

async function main() {
  await mkdir(PUBLIC_DIR, { recursive: true })

  const [llmsTxt, llmsFullTxt] = await Promise.all([
    generateLlmsTxt(),
    generateLlmsFullTxt(),
  ])

  // This script runs after `vite build` (which copies public/ into dist/client),
  // so write into dist/client too; otherwise a clean build would never contain
  // the exports and production would always fall back to runtime generation.
  const outputDirs = [PUBLIC_DIR]
  const clientDir = join(VIBES_ROOT, 'dist', 'client')
  if (existsSync(clientDir)) outputDirs.push(clientDir)

  await Promise.all(
    outputDirs.flatMap((dir) => [
      writeFile(join(dir, 'llms.txt'), llmsTxt, 'utf-8'),
      writeFile(join(dir, 'llms-full.txt'), llmsFullTxt, 'utf-8'),
    ]),
  )

  console.log('Generated llms.txt and llms-full.txt')
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
