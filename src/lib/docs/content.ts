import { DOCS_PAGE_MAP } from './generated/manifest'
import { stripFrontmatter } from './frontmatter'
import { preloadPartialsForContent, resolvePartials } from './partials'
import { extractDocsToc } from './toc'
import type { DocsPageData, DocsPageMeta } from './types'

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
  const cached = rawContentCache.get(slug)
  if (cached !== undefined) return touchRawContentCache(slug, cached)

  const modulePath = contentPathBySlug.get(slug)
  if (!modulePath) return null

  const loader = contentLoaders[modulePath]
  if (!loader) return null

  const raw = await loader()
  return touchRawContentCache(slug, raw)
}

function preprocessMarkdocContent(content: string): string {
  let result = content

  result = result.replace(
    /^(#{1,6})\s+(.+?)\s*\{%\s*#([-\w]+)\s*%\}\s*$/gm,
    (_, hashes: string, title: string, id: string) => `${hashes} ${title} {#${id}}`,
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

  return {
    meta,
    content,
    rawContent: withPartials,
    toc: extractDocsToc(withPartials),
  }
}

export function getDocsSlugFromPath(pathname: string): string {
  const normalized = pathname.replace(/\/+$/, '')
  if (normalized === '/docs') return ''
  if (!normalized.startsWith('/docs/')) return ''
  return normalized.slice('/docs/'.length)
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

export async function getDocsMarkdownExport(slug: string): Promise<string | null> {
  const page = await getDocsPage(slug)
  if (!page) return null
  return page.rawContent
}
