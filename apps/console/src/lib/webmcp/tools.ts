import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductId } from '@/lib/products/types'

declare global {
  interface Navigator {
    /** Pre-spec Chrome builds expose WebMCP here instead of on `document`. */
    readonly modelContext?: WebMCP.ModelContext
  }
}

/** Resolved instead of thrown, so the agent sees why a call failed. */
type ToolError = { error: string; code: string; retryable: boolean }

type Navigate = (path: string) => Promise<void>

const MARKDOWN_LIMIT = 100_000

function failure(code: string, error: string, retryable = false): ToolError {
  return { error, code, retryable }
}

function toUrl(path: string): string {
  return `${window.location.origin}${path}`
}

/** A same-site page path such as `/docs/products/auth`; never a URL or `//host`. */
function sitePath(value: string): string | null {
  const path = value.trim()
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\')) {
    return null
  }
  const pathname = new URL(path, window.location.origin).pathname
  return pathname.replace(/\/+$/, '') || '/'
}

async function fetchMarkdown(
  path: string,
  signal: AbortSignal,
): Promise<{ url: string; markdown: string; truncated: boolean } | ToolError> {
  let response: Response
  try {
    response = await fetch(path, {
      headers: { Accept: 'text/markdown' },
      signal,
    })
  } catch {
    return failure('network_error', `Could not fetch ${path}.`, true)
  }
  // Unknown docs paths redirect to an HTML section page rather than 404.
  const markdownType = response.headers
    .get('content-type')
    ?.startsWith('text/markdown')
  if (
    response.status === 404 ||
    (response.ok && (response.redirected || !markdownType))
  ) {
    return failure('not_found', `No page exists at ${path}.`)
  }
  if (!response.ok) {
    return failure(
      'fetch_failed',
      `${path} returned HTTP ${response.status}.`,
      response.status >= 500,
    )
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
    return failure(
      'invalid_path',
      'path must be a docs path like /docs/products/auth.',
    )
  }
  return fetchMarkdown(`${page}.md`, signal)
}

async function getProductOverview(
  { product }: { product: ProductId },
  signal: AbortSignal,
) {
  const entry = PRODUCT_REGISTRY[product]
  if (!entry) {
    return failure('invalid_product', `Unknown product "${product}".`)
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

const CHANGELOG_LINK = /^- \[(.+)\]\((https?:\/\/[^)\s]+\.md)\)/

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
    return failure(
      'search_failed',
      'Community thread search is unavailable.',
      true,
    )
  }
}

async function navigateToPage({ path }: { path: string }, navigate: Navigate) {
  const page = sitePath(path)
  if (!page) {
    return failure(
      'invalid_path',
      'path must start with / and stay on this site.',
    )
  }
  await navigate(page)
  return { navigated: true, url: toUrl(page) }
}

/**
 * Registers appwrite.io's in-page tools with WebMCP so a browser agent can
 * search and read the docs, check pricing and products, and move around the
 * site. Every definition is a literal so scanners can read it from the bundle.
 * Returns the cleanup that unregisters them.
 */
export function registerWebMcpTools(navigate: Navigate): () => void {
  const modelContext = document.modelContext ?? navigator.modelContext
  if (!modelContext) return () => {}

  const controller = new AbortController()
  const options = { signal: controller.signal }
  const register = (registration: Promise<void>) => {
    registration.catch((error: unknown) => {
      if (!controller.signal.aborted)
        console.warn('WebMCP tool registration failed', error)
    })
  }

  register(
    modelContext.registerTool(
      {
        name: 'search_docs',
        title: 'Search Appwrite docs',
        description:
          'Search the Appwrite documentation and return the best-matching pages with their titles, summaries, and URLs.',
        inputSchema: {
          type: 'object',
          properties: {
            query: {
              type: 'string',
              minLength: 2,
              maxLength: 200,
              description:
                'What to look for, such as "create a row" or "OAuth with Google".',
            },
            limit: {
              type: 'integer',
              minimum: 1,
              maximum: 10,
              description: 'Maximum number of pages to return. Defaults to 5.',
            },
          },
          required: ['query'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input) => searchDocs(input),
      },
      options,
    ),
  )

  register(
    modelContext.registerTool(
      {
        name: 'get_doc_page',
        title: 'Read an Appwrite docs page',
        description:
          'Return the Markdown source of one Appwrite documentation page, given its path from search_docs.',
        inputSchema: {
          type: 'object',
          properties: {
            path: {
              type: 'string',
              pattern: '^/docs(/[A-Za-z0-9._-]+)*/?$',
              description:
                'Docs page path, such as /docs/products/auth/email-password.',
            },
          },
          required: ['path'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input, { signal }) => getDocPage(input, signal),
      },
      options,
    ),
  )

  register(
    modelContext.registerTool(
      {
        name: 'get_pricing',
        title: 'Get Appwrite Cloud pricing',
        description:
          'Return Appwrite Cloud plans, prices, included usage, and add-on rates as Markdown.',
        inputSchema: {
          type: 'object',
          properties: {},
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (_input, { signal }) => fetchMarkdown('/pricing.md', signal),
      },
      options,
    ),
  )

  register(
    modelContext.registerTool(
      {
        name: 'get_product_overview',
        title: 'Get an Appwrite product overview',
        description:
          'Return what one Appwrite product does, its main features, and its docs link, as Markdown.',
        inputSchema: {
          type: 'object',
          properties: {
            product: {
              type: 'string',
              enum: [
                'auth',
                'databases',
                'postgres',
                'storage',
                'functions',
                'messaging',
                'realtime',
                'sites',
                'analytics',
                'firewall',
              ] satisfies ProductId[],
              description: 'Product to describe.',
            },
          },
          required: ['product'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input, { signal }) => getProductOverview(input, signal),
      },
      options,
    ),
  )

  register(
    modelContext.registerTool(
      {
        name: 'list_changelog',
        title: 'List recent Appwrite changes',
        description:
          'Return the newest Appwrite changelog entries, newest first, with links to each entry.',
        inputSchema: {
          type: 'object',
          properties: {
            limit: {
              type: 'integer',
              minimum: 1,
              maximum: 20,
              description: 'Number of entries to return. Defaults to 5.',
            },
          },
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input, { signal }) => listChangelog(input, signal),
      },
      options,
    ),
  )

  register(
    modelContext.registerTool(
      {
        name: 'search_community_threads',
        title: 'Search Appwrite community threads',
        description:
          'Search Appwrite Discord support threads written by community members and return matching threads with summaries and links.',
        inputSchema: {
          type: 'object',
          properties: {
            query: {
              type: 'string',
              minLength: 2,
              maxLength: 200,
              description:
                'Problem or topic to look for, such as "CORS error on login".',
            },
            limit: {
              type: 'integer',
              minimum: 1,
              maximum: 10,
              description:
                'Maximum number of threads to return. Defaults to 5.',
            },
          },
          required: ['query'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true, untrustedContentHint: true },
        execute: (input) => searchCommunityThreads(input),
      },
      options,
    ),
  )

  register(
    modelContext.registerTool(
      {
        name: 'navigate_to_page',
        title: 'Open a page on appwrite.io',
        description:
          'Open a page on this site in the current tab, such as a docs page, product page, or /pricing.',
        inputSchema: {
          type: 'object',
          properties: {
            path: {
              type: 'string',
              pattern: '^/(?!/)',
              description:
                'Site path to open, such as /docs/quick-starts/react or /pricing.',
            },
          },
          required: ['path'],
          additionalProperties: false,
        },
        execute: (input) => navigateToPage(input, navigate),
      },
      options,
    ),
  )

  register(
    modelContext.registerTool(
      {
        name: 'open_sign_up',
        title: 'Open Appwrite Cloud sign-up',
        description:
          'Open the Appwrite Cloud sign-up page in the current tab so the person can create a free account.',
        inputSchema: {
          type: 'object',
          properties: {},
          additionalProperties: false,
        },
        execute: () => navigateToPage({ path: '/sign-up' }, navigate),
      },
      options,
    ),
  )

  return () => controller.abort()
}
