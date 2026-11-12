import { createMiddleware } from '@tanstack/react-start'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { generateLlmsTxt } from '@/lib/seo/llms-content'
import {
  HOME_LINK_HEADER,
  prefersMarkdown,
} from '@/lib/seo/markdown-negotiation'
import { trackServerPageview } from '@/lib/server-analytics'

/**
 * Homepage content negotiation: `Accept: text/markdown` gets the llms.txt hub,
 * and the HTML homepage advertises agent resources in `Link`.
 */
export const markdownNegotiationMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  if (pathname !== '/') {
    return next()
  }

  if (prefersMarkdown(request.headers.get('accept'))) {
    trackServerPageview(request)
    const response = await respondWithPrebuiltOrRuntime(
      'llms.txt',
      'text/markdown; charset=utf-8',
      () => generateLlmsTxt(),
    )
    const headers = new Headers(response.headers)
    headers.set('Vary', 'Accept')
    headers.set('Link', HOME_LINK_HEADER)
    throw new Response(response.body, { status: response.status, headers })
  }

  const result = await next()
  if (!result.response) {
    return result
  }

  const headers = new Headers(result.response.headers)
  headers.append('Vary', 'Accept')
  headers.set('Link', HOME_LINK_HEADER)
  return {
    ...result,
    response: new Response(result.response.body, {
      status: result.response.status,
      statusText: result.response.statusText,
      headers,
    }),
  }
})
