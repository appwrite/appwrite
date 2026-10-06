/** TanStack Router head `links` entry for canonical URLs (not valid inside `meta`). */
export type CanonicalLink = {
  rel: 'canonical'
  href: string
}

type MetaTag = Record<string, string>

function isLegacyCanonicalLinkMeta(tag: MetaTag): boolean {
  return tag.tag === 'link' && tag.rel === 'canonical' && Boolean(tag.href)
}

export function getCanonicalLink(href: string): CanonicalLink {
  return { rel: 'canonical', href }
}

export function canonicalLinks(href?: string): CanonicalLink[] {
  return href ? [getCanonicalLink(href)] : []
}

/**
 * Build route `head()` payload from SEO meta tags. Canonical href comes from
 * `og:url` unless overridden (marketing pages pass an explicit URL).
 */
export function asRouteHead(
  metaTags: readonly MetaTag[],
  options?: { canonicalHref?: string },
): { meta: MetaTag[]; links: CanonicalLink[] } {
  const meta = metaTags.filter((tag) => !isLegacyCanonicalLinkMeta(tag))
  const canonicalHref =
    options?.canonicalHref?.trim() ||
    metaTags.find((tag) => tag.property === 'og:url')?.content?.trim() ||
    metaTags.find(isLegacyCanonicalLinkMeta)?.href?.trim()

  return {
    meta: [...meta],
    links: canonicalLinks(canonicalHref),
  }
}
