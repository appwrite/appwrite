import { createMiddleware } from '@tanstack/react-start'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { DEFAULT_LLMS_ORIGIN } from '@/lib/seo/llms'
import { generateLlmsTxt } from '@/lib/seo/llms-content'
import { markdownTwinPage } from '@/lib/seo/markdown-twins'
import {
  HOME_LINK_HEADER,
  prefersMarkdown,
} from '@/lib/seo/markdown-negotiation'
import { trackServerPageview } from '@/lib/server-analytics'

/**
 * Homepage content negotiation: `Accept: text/markdown` gets the llms.txt hub,
 * and the HTML homepage advertises agent resources in `Link`. Marketing pages
 * get a `.md` twin converted from their rendered HTML.
 */
export const markdownNegotiationMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const page = markdownTwinPage(pathname)
  if (page) {
    trackServerPageview(request)
    // Loaded here so the converter stays out of the client bundle.
    const { pageHtmlToMarkdown } = await import('@/lib/seo/page-markdown')
    throw await respondWithPrebuiltOrRuntime(
      `${page.slice(1)}.md`,
      'text/markdown; charset=utf-8',
      async () => {
        const html = await (await fetch(new URL(page, request.url))).text()
        return pageHtmlToMarkdown(html, DEFAULT_LLMS_ORIGIN)
      },
    )
  }

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
