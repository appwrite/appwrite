/** Legacy singular slugs from posts and inbound links → canonical plural category slugs. */
export const BLOG_CATEGORY_SLUG_ALIASES: Record<string, string> = {
  announcement: 'announcements',
  tutorial: 'tutorials',
  product: 'products',
  startup: 'startups',
  hackathon: 'hackathons',
  architecture: 'architectures',
  company: 'companies',
  migration: 'migrations',
}

export function normalizeCategorySlug(value: string): string {
  return value.replace(/\s+/g, '-').toLowerCase()
}

export function resolveCategorySlug(slug: string): string {
  const normalized = normalizeCategorySlug(slug)
  return BLOG_CATEGORY_SLUG_ALIASES[normalized] ?? normalized
}
