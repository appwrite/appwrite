import { normalizeDocsRoutePathname } from '@/lib/docs/docs-slug'

export function shouldResetDocsScrollOnPathChange(
  previousNormalizedPath: string | null,
  nextPathname: string,
): { nextNormalizedPath: string; shouldScroll: boolean } {
  const nextNormalizedPath = normalizeDocsRoutePathname(nextPathname)

  if (previousNormalizedPath === null) {
    return { nextNormalizedPath, shouldScroll: false }
  }

  return {
    nextNormalizedPath,
    shouldScroll: previousNormalizedPath !== nextNormalizedPath,
  }
}

export function docsArticlePathForSlug(slug: string): string {
  return normalizeDocsRoutePathname(`/docs/${slug}`)
}
