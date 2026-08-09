import { DOCS_SEARCH_INDEX } from './generated/search-index'
import {
  isAgentDocsEnabled,
  isAgentDocsSlug,
} from './agent-docs-feature'
import {
  isFirewallDocsEnabled,
  isFirewallDocsSlug,
} from './firewall-docs-feature'
import { isPartnersDocsEnabled, isPartnersDocsSlug } from './partners-docs-feature'

export type DocsSearchEntry = {
  slug: string
  title: string
  description: string
  excerpt: string
  breadcrumbs: string[]
}

export type DocsSearchResult = DocsSearchEntry & {
  score: number
}

function normalizeQuery(query: string): string {
  return query.trim().toLowerCase()
}

function tokenize(query: string): string[] {
  return normalizeQuery(query)
    .split(/\s+/)
    .filter((token) => token.length >= 2)
}

function scoreEntry(entry: DocsSearchEntry, query: string): number {
  const normalizedQuery = normalizeQuery(query)
  if (!normalizedQuery) return 0

  const title = entry.title.toLowerCase()
  const description = entry.description.toLowerCase()
  const excerpt = entry.excerpt.toLowerCase()
  const slug = entry.slug.toLowerCase()
  const slugAsWords = slug.replace(/-/g, ' ')
  const breadcrumbs = entry.breadcrumbs.join(' ').toLowerCase()
  const haystack = `${title} ${description} ${excerpt} ${slugAsWords} ${breadcrumbs}`

  let score = 0

  if (title === normalizedQuery) score += 200
  else if (title.startsWith(normalizedQuery)) score += 140
  else if (title.includes(normalizedQuery)) score += 110

  if (slug === normalizedQuery.replace(/\s+/g, '-')) score += 120
  else if (slug.includes(normalizedQuery.replace(/\s+/g, '-'))) score += 90

  if (description.includes(normalizedQuery)) score += 70
  if (excerpt.includes(normalizedQuery)) score += 45

  for (const token of tokenize(normalizedQuery)) {
    if (title.includes(token)) score += 24
    if (slug.includes(token)) score += 18
    if (description.includes(token)) score += 12
    if (excerpt.includes(token)) score += 8
    if (haystack.includes(token)) score += 4
  }

  return score
}

function isHiddenDocsSearchSlug(slug: string): boolean {
  if (!isPartnersDocsEnabled() && isPartnersDocsSlug(slug)) return true
  if (!isFirewallDocsEnabled() && isFirewallDocsSlug(slug)) return true
  if (!isAgentDocsEnabled() && isAgentDocsSlug(slug)) return true
  return false
}

function getVisibleSearchIndex(index: DocsSearchEntry[] = DOCS_SEARCH_INDEX): DocsSearchEntry[] {
  return index.filter((entry) => !isHiddenDocsSearchSlug(entry.slug))
}

export function searchDocs(
  query: string,
  limit = 12,
  index: DocsSearchEntry[] = DOCS_SEARCH_INDEX,
): DocsSearchResult[] {
  const normalizedQuery = normalizeQuery(query)
  if (!normalizedQuery) return []

  return getVisibleSearchIndex(index)
    .map((entry) => ({
      ...entry,
      score: scoreEntry(entry, normalizedQuery),
    }))
    .filter((entry) => entry.score > 0)
    .sort((a, b) => b.score - a.score || a.title.localeCompare(b.title))
    .slice(0, limit)
}

export function getDocsSearchHref(slug: string): string {
  return slug ? `/docs/${slug}` : '/docs'
}

export const DOCS_SEARCH_SUGGESTIONS = [
  'OAuth login',
  'API keys',
  'Storage buckets',
  'Database queries',
  'Deploy function',
] as const

const DOCS_SEARCH_POPULAR_SLUGS = [
  'quick-starts',
  'products/auth',
  'products/databases',
  'products/storage',
  'products/functions',
  'references',
] as const

export function getDocsSearchPopularPages(
  index: DocsSearchEntry[] = DOCS_SEARCH_INDEX,
): DocsSearchEntry[] {
  const bySlug = new Map(getVisibleSearchIndex(index).map((entry) => [entry.slug, entry]))
  return DOCS_SEARCH_POPULAR_SLUGS.flatMap((slug) => {
    const entry = bySlug.get(slug)
    return entry ? [entry] : []
  })
}

export const DOCS_SEARCH_PAGE_COUNT = DOCS_SEARCH_INDEX.length
