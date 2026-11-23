import { parseDocsPagePath, splitHrefHash } from '@/lib/marketing/urls'

export function docsHrefToPreviewSlug(href: string): string | null {
  const { pathname } = splitHrefHash(href)
  const docsPath = parseDocsPagePath(pathname)
  if (!docsPath) return null
  if (docsPath === '/docs') return ''
  return docsPath.slice('/docs/'.length)
}

/**
 * File routes that own these exact paths. Linking them through `/docs/$`
 * makes TanStack Router warn that the generated URL matched a more specific
 * route instead.
 */
const DEDICATED_DOCS_HUB_PATHS = {
  '/docs/quick-starts': '/docs/quick-starts',
  '/docs/tutorials': '/docs/tutorials',
} as const

type DedicatedDocsHubPath =
  (typeof DEDICATED_DOCS_HUB_PATHS)[keyof typeof DEDICATED_DOCS_HUB_PATHS]

export function docsHrefToRoute(href: string) {
  const { pathname, hash } = splitHrefHash(href)
  const hashValue = hash || undefined

  if (pathname === '/docs' || pathname === '/docs/') {
    return { to: '/docs' as const, params: undefined, hash: hashValue }
  }

  if (pathname in DEDICATED_DOCS_HUB_PATHS) {
    return {
      to: DEDICATED_DOCS_HUB_PATHS[pathname as DedicatedDocsHubPath],
      params: undefined,
      hash: hashValue,
    }
  }

  if (pathname === '/docs/partners' || pathname === '/docs/partners/') {
    return {
      to: '/docs/partners/' as const,
      params: undefined,
      hash: hashValue,
    }
  }

  const referencePath = pathname.match(
    /^\/docs\/references\/([^/]+)\/([^/]+)\/([^/]+)\/?$/,
  )
  if (referencePath) {
    const [, version, platformOrModels, serviceOrModel] = referencePath
    if (platformOrModels === 'models') {
      return {
        to: '/docs/references/$version/models/$model' as const,
        params: { version, model: serviceOrModel },
        hash: hashValue,
      }
    }
    return {
      to: '/docs/references/$version/$platform/$service' as const,
      params: {
        version,
        platform: platformOrModels,
        service: serviceOrModel,
      },
      hash: hashValue,
    }
  }

  if (pathname.startsWith('/docs/')) {
    return {
      to: '/docs/$' as const,
      params: { _splat: pathname.slice('/docs/'.length) },
      hash: hashValue,
    }
  }

  return null
}
