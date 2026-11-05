import { markdocToMarkdown } from '@/lib/seo/markdoc-to-markdown'
import { DOCS_PAGE_MAP } from './generated/manifest'
import { parseFrontmatterString, stripFrontmatter } from './frontmatter'
import { preloadPartialsForContent, resolvePartials } from './partials'
import { resolveDocsPagePrompt } from './route-prompts'
import { extractDocsToc } from './toc'
import type { DocsPageData, DocsPageMeta } from './types'

export { getDocsSlugFromPath } from './docs-slug'

/**
 * Lazy glob keeps markdoc edits from forcing a full Vite program reload of this
 * module (eager globs did). Browser DEV loads always fetch with a cache-bust so
 * HMR still sees fresh content.
 */
const contentLoaders = import.meta.glob('/src/content/docs/**/index.markdoc', {
  query: '?raw',
  import: 'default',
}) as Record<string, () => Promise<string>>

function slugFromModulePath(modulePath: string): string {
  const match = modulePath.match(/\/src\/content\/docs\/(.*)\/index\.markdoc$/)
  if (!match) return ''
  return match[1]
}

const contentPathBySlug = new Map<string, string>()
for (const modulePath of Object.keys(contentLoaders)) {
  contentPathBySlug.set(slugFromModulePath(modulePath), modulePath)
}

const rawContentCache = new Map<string, string>()
/** Bound SSR doc cache so crawlers cannot retain every markdoc file in memory. */
const RAW_CONTENT_CACHE_MAX = 64

function touchRawContentCache(slug: string, value: string): string {
  if (rawContentCache.has(slug)) {
    rawContentCache.delete(slug)
  } else if (rawContentCache.size >= RAW_CONTENT_CACHE_MAX) {
    const oldest = rawContentCache.keys().next().value
    if (oldest !== undefined) rawContentCache.delete(oldest)
  }
  rawContentCache.set(slug, value)
  return value
}

async function loadRawContent(slug: string): Promise<string | null> {
  const modulePath = contentPathBySlug.get(slug)
  if (!modulePath) return null

  if (import.meta.env.DEV && typeof window !== 'undefined') {
    try {
      const response = await fetch(`${modulePath}?t=${Date.now()}`)
      if (!response.ok) return null
      return await response.text()
    } catch {
      // Fall through to glob loader.
    }
  }

  if (!import.meta.env.DEV) {
    const cached = rawContentCache.get(slug)
    if (cached !== undefined) return touchRawContentCache(slug, cached)
  }

  const loader = contentLoaders[modulePath]
  if (!loader) return null

  const raw = await loader()
  if (import.meta.env.DEV) return raw
  return touchRawContentCache(slug, raw)
}

function preprocessMarkdocContent(content: string): string {
  let result = content

  result = result.replace(
    /^(#{1,6})\s+(.+?)\s*\{%\s*#([-\w]+)\s*%\}\s*$/gm,
    (_, hashes: string, title: string, id: string) =>
      `${hashes} ${title} {#${id}}`,
  )

  result = result.replace(
    /\{%\s*section\s+([^%]+?)\s*%\}/g,
    (_, attrs: string) => {
      const idMatch = attrs.match(/#([-\w]+)/)
      const titleMatch = attrs.match(/\btitle="([^"]*)"/)
      const stepMatch = attrs.match(/\bstep=(\d+)\b/)
      if (!idMatch) return ''
      const title = titleMatch?.[1] ?? idMatch[1]
      const stepPrefix = stepMatch ? `${stepMatch[1]}. ` : ''
      return `\n## ${stepPrefix}${title} {#${idMatch[1]}}\n`
    },
  )

  return result
}

function buildDocsPage(meta: DocsPageMeta, raw: string): DocsPageData {
  const withPartials = resolvePartials(raw)
  const preprocessed = preprocessMarkdocContent(withPartials)
  const content = stripFrontmatter(preprocessed)

  const promptPath = parseFrontmatterString(withPartials, 'prompt')

  return {
    meta,
    content,
    rawContent: withPartials,
    toc: extractDocsToc(withPartials),
    ...(promptPath ? { promptPath } : {}),
  }
}

export function getDocsPageMeta(slug: string): DocsPageMeta | null {
  return DOCS_PAGE_MAP[slug] ?? null
}

export async function getDocsPage(slug: string): Promise<DocsPageData | null> {
  const meta = getDocsPageMeta(slug)
  if (!meta) return null

  const raw = await loadRawContent(slug)
  if (!raw) return null

  await preloadPartialsForContent(raw)
  return buildDocsPage(meta, raw)
}

export async function getAllDocsPages(): Promise<DocsPageData[]> {
  const pages = await Promise.all(
    Object.keys(DOCS_PAGE_MAP).map((slug) => getDocsPage(slug)),
  )
  return pages.filter((page): page is DocsPageData => page !== null)
}

export async function getDocsMarkdownExport(
  slug: string,
): Promise<string | null> {
  const page = await getDocsPage(slug)
  if (!page) return null
  const markdown = markdocToMarkdown(page.rawContent)

  // The agent prompt renders as a UI banner, so the markdoc source never
  // contains it. Append it so agents reading the export get it too.
  const prompt = resolveDocsPagePrompt(slug, page.promptPath)?.trim()
  if (!prompt) return markdown

  return [
    markdown.trimEnd(),
    '',
    '## Agent prompt',
    '',
    'Paste this prompt into a coding agent to set up this guide.',
    '',
    '````markdown',
    prompt,
    '````',
    '',
  ].join('\n')
}
