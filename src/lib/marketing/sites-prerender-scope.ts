/**
 * Build-time prerender scope for Appwrite Sites (FOR_SITES=true).
 *
 * Default `core` keeps peak RSS under ~4GB by skipping hundreds of blog post and
 * changelog entry pages (they are still served via SSR at runtime). Set
 * SITES_PRERENDER_SCOPE=full on larger build workers to emit static HTML for every
 * marketing URL.
 */
export type SitesPrerenderScope = 'core' | 'full'

export function getSitesPrerenderScope(): SitesPrerenderScope {
  const raw = process.env.SITES_PRERENDER_SCOPE?.trim().toLowerCase()
  return raw === 'full' ? 'full' : 'core'
}

export function sitesPrerenderIncludesBlogPosts(): boolean {
  return getSitesPrerenderScope() === 'full'
}

export function sitesPrerenderIncludesChangelogEntries(): boolean {
  return getSitesPrerenderScope() === 'full'
}

export function getSitesPrerenderConcurrency(): number {
  const parsed = Number(process.env.SITES_PRERENDER_CONCURRENCY ?? 1)
  return Number.isFinite(parsed) ? Math.max(1, parsed) : 1
}
