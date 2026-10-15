import { createMiddleware } from '@tanstack/react-start'
import {
  applyNoIndexResponseHeaders,
  getNonProductionRobotsTxt,
  getRequestHostFromHeaders,
  isSeoIndexableHost,
  NOINDEX_ROBOTS_HEADER,
} from '@/lib/seo/indexing'
import { isSearchNonIndexablePath } from '@/lib/seo/non-indexable-paths'
import { trackServerPageview } from '@/lib/server-analytics'

export const seoIndexingMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const host = getRequestHostFromHeaders(request.headers, request.url)
  const indexable = isSeoIndexableHost(host)

  if (pathname === '/robots.txt' && !indexable) {
    trackServerPageview(request, { format: 'text' })
    throw new Response(getNonProductionRobotsTxt(), {
      status: 200,
      headers: {
        'Content-Type': 'text/plain; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-Robots-Tag': NOINDEX_ROBOTS_HEADER,
      },
    })
  }

  const result = await next()
  const response = result.response
  if (!response) {
    return result
  }

  const shouldNoIndex =
    !indexable ||
    isSearchNonIndexablePath(
      pathname ??
        (() => {
          try {
            return new URL(request.url).pathname
          } catch {
            return '/'
          }
        })(),
    )

  if (!shouldNoIndex) {
    return result
  }

  const headers = applyNoIndexResponseHeaders(response.headers)
  return {
    ...result,
    response: new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers,
    }),
  }
})
