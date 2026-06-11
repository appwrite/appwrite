import type { DocsPageMeta } from './types'
import { getDocsMetaTags as buildDocsMetaTags } from './seo'

export function getDocsMetaTags(
  meta: DocsPageMeta | { title: string; description: string; slug: string },
) {
  const pageMeta: DocsPageMeta = {
    slug: meta.slug,
    title: meta.title,
    description: meta.description,
    layout: 'article',
  }
  return buildDocsMetaTags(pageMeta, meta.slug) as unknown as Array<
    Record<string, string>
  >
}
