import { DOCS_PAGE_MAP } from './generated/manifest'
import { stripFrontmatter } from './frontmatter'
import { resolvePartials } from './partials'
import { extractDocsToc } from './toc'
import type { DocsPageData, DocsPageMeta } from './types'

const contentModules = import.meta.glob('/src/content/docs/**/index.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

function slugFromModulePath(path: string): string {
  const match = path.match(/\/src\/content\/docs\/(.*)\/index\.markdoc$/)
  if (!match) return ''
  return match[1]
}

const CONTENT_BY_SLUG = new Map<string, string>()
for (const [path, raw] of Object.entries(contentModules)) {
  CONTENT_BY_SLUG.set(slugFromModulePath(path), raw)
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

export function getDocsSlugFromPath(pathname: string): string {
  const normalized = pathname.replace(/\/+$/, '')
  if (normalized === '/docs') return ''
  if (!normalized.startsWith('/docs/')) return ''
  return normalized.slice('/docs/'.length)
}

export function getDocsPageMeta(slug: string): DocsPageMeta | null {
  return DOCS_PAGE_MAP[slug] ?? null
}

export function getDocsPage(slug: string): DocsPageData | null {
  const meta = getDocsPageMeta(slug)
  const raw = CONTENT_BY_SLUG.get(slug)
  if (!meta || !raw) return null

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

export function getAllDocsPages(): DocsPageData[] {
  return Object.keys(DOCS_PAGE_MAP)
    .map((slug) => getDocsPage(slug))
    .filter((page): page is DocsPageData => page !== null)
}

export function getDocsMarkdownExport(slug: string): string | null {
  const page = getDocsPage(slug)
  if (!page) return null
  return page.rawContent
}
