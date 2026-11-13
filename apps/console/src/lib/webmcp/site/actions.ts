import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductId } from '@/lib/products/types'
import { toolError, type ToolError, type ToolHandler } from '@/lib/webmcp/calls'

/** What appwrite.io's tools do. Each resolves a JSON-serialisable result or a ToolError. */

export type Navigate = (path: string) => Promise<void>

type MarkdownPage = { url: string; markdown: string; truncated: boolean }

const MARKDOWN_LIMIT = 100_000
const CHANGELOG_LINK = /^- \[(.+)\]\((https?:\/\/[^)\s]+\.md)\)/

function toUrl(path: string): string {
  return `${window.location.origin}${path}`
}

/** A same-site page path such as `/docs/products/auth`; never a URL or `//host`, before or after normalization. */
function sitePath(value: string): string | null {
  const path = value.trim()
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\')) {
    return null
  }
  const url = new URL(path, window.location.origin)
  // Dot segments can normalize to a protocol-relative path: /..//host -> //host.
  if (url.origin !== window.location.origin || url.pathname.startsWith('//')) {
    return null
  }
  return url.pathname.replace(/\/+$/, '') || '/'
}

async function fetchMarkdown(
  path: string,
  signal: AbortSignal,
): Promise<MarkdownPage | ToolError> {
  let response: Response
  try {
    response = await fetch(path, {
      headers: { Accept: 'text/markdown' },
      signal,
    })
  } catch {
    return toolError('network_error', `Could not fetch ${path}.`, true)
  }
  if (response.status === 404) {
    return toolError('not_found', `No page exists at ${path}.`)
  }
  if (!response.ok) {
    return toolError(
      'fetch_failed',
      `${path} returned HTTP ${response.status}.`,
      response.status >= 500,
    )
  }
  if (!response.headers.get('content-type')?.startsWith('text/markdown')) {
    // Unknown docs paths redirect to an HTML section page instead of 404ing.
    return response.redirected
      ? toolError('not_found', `No page exists at ${path}.`)
      : toolError('fetch_failed', `${path} did not return Markdown.`)
  }
  const markdown = await response.text()
  return {
    url: toUrl(path),
    markdown: markdown.slice(0, MARKDOWN_LIMIT),
    truncated: markdown.length > MARKDOWN_LIMIT,
  }
}

async function searchDocs({ query, limit }: { query: string; limit?: number }) {
  const { searchDocs } = await import('@/lib/docs/search')
  const results = searchDocs(query, limit ?? 5).map((entry) => ({
    title: entry.title,
    description: entry.description,
    section: entry.breadcrumbs.join(' / '),
    url: toUrl(`/docs/${entry.slug}`),
    markdownUrl: toUrl(`/docs/${entry.slug}.md`),
  }))
  return { query, results }
}

async function getDocPage({ path }: { path: string }, signal: AbortSignal) {
  const page = sitePath(path.replace(/\.md$/, ''))
  if (!page || (page !== '/docs' && !page.startsWith('/docs/'))) {
    return toolError(
      'invalid_path',
      'path must be a docs path like /docs/products/auth.',
    )
  }
  return fetchMarkdown(`${page}.md`, signal)
}

function getPricing(signal: AbortSignal) {
  return fetchMarkdown('/pricing.md', signal)
}

async function getProductOverview(
  { product }: { product: ProductId },
  signal: AbortSignal,
) {
  const entry = PRODUCT_REGISTRY[product]
  if (!entry) {
    return toolError('invalid_product', `Unknown product "${product}".`)
  }
  const page = await fetchMarkdown(`${entry.path}.md`, signal)
  if ('error' in page) return page
  return {
    product: entry.id,
    name: entry.name,
    tagline: entry.tagline,
    docsUrl: toUrl(entry.docsPath),
    ...page,
  }
}

async function listChangelog(
  { limit }: { limit?: number },
  signal: AbortSignal,
) {
  const index = await fetchMarkdown('/changelog.md', signal)
  if ('error' in index) return index
  const entries = index.markdown
    .split('\n')
    .map((line) => line.match(CHANGELOG_LINK))
    .filter((match) => match !== null)
    .slice(0, limit ?? 5)
    .map(([, title, markdownUrl]) => ({
      title,
      url: markdownUrl.replace(/\.md$/, ''),
      markdownUrl,
    }))
  return { entries }
}

async function searchCommunityThreads({
  query,
  limit,
}: {
  query: string
  limit?: number
}) {
  const { getThreadHref, getThreads, isThreadResolved } = await import(
    '@/lib/threads/content'
  )
  try {
    const { threads } = await getThreads({ q: query })
    return {
      query,
      threads: threads.slice(0, limit ?? 5).map((thread) => ({
        title: thread.title,
        summary: thread.tldr,
        tags: thread.tags ?? [],
        resolved: isThreadResolved(thread),
        replies: thread.message_count,
        url: toUrl(getThreadHref(thread)),
      })),
    }
  } catch {
    return toolError(
      'search_failed',
      'Community thread search is unavailable.',
      true,
    )
  }
}

async function navigateToPage({ path }: { path: string }, navigate: Navigate) {
  const page = sitePath(path)
  if (!page) {
    return toolError(
      'invalid_path',
      'path must start with / and stay on this site.',
    )
  }
  // Leaving the layout unregisters this tool, and a tool unregistered while it
  // runs never delivers its result; so answer first, then navigate.
  setTimeout(() => void navigate(page))
  return { navigating: true, url: toUrl(page) }
}

/** What each of appwrite.io's tools does, keyed by the tool name `site/tools.ts` registers. */
export function siteHandlers(navigate: Navigate): Record<string, ToolHandler> {
  return {
    search_docs: async (input) =>
      searchDocs(input as { query: string; limit?: number }),
    get_doc_page: async (input, { signal }) =>
      getDocPage(input as { path: string }, signal),
    get_pricing: async (_input, { signal }) => getPricing(signal),
    get_product_overview: async (input, { signal }) =>
      getProductOverview(input as { product: ProductId }, signal),
    list_changelog: async (input, { signal }) =>
      listChangelog(input as { limit?: number }, signal),
    search_community_threads: async (input) =>
      searchCommunityThreads(input as { query: string; limit?: number }),
    navigate_to_page: async (input) =>
      navigateToPage(input as { path: string }, navigate),
    navigate_to_sign_up: async () =>
      navigateToPage({ path: '/sign-up' }, navigate),
  }
}
