import { NOINDEX_FOLLOW_ROBOTS_META } from '@/lib/seo/indexing'
import { asRouteHead } from '@/lib/seo/route-head'
import type { DocsPageMeta } from './types'
import { getDocsMetaTags as buildDocsMetaTags } from './seo'

export function getDocsMetaTags(
  meta: DocsPageMeta | { title: string; description: string; slug: string },
  options?: { canonicalSlug?: string; siteOrigin?: string },
) {
  const pageMeta: DocsPageMeta = {
    slug: meta.slug,
    title: meta.title,
    description: meta.description,
    layout: 'article',
  }
  return buildDocsMetaTags(pageMeta, meta.slug, options) as unknown as Array<
    Record<string, string>
  >
}

export function getDocsRouteHead(
  meta: DocsPageMeta | { title: string; description: string; slug: string },
  options?: { canonicalSlug?: string; siteOrigin?: string },
) {
  const canonicalSlug = options?.canonicalSlug ?? meta.slug
  const head = asRouteHead(getDocsMetaTags(meta, options))

  if (canonicalSlug !== meta.slug) {
    return {
      ...head,
      meta: [...head.meta, NOINDEX_FOLLOW_ROBOTS_META],
    }
  }

  return head
}
