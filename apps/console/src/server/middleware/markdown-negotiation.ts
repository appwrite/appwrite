import { createMiddleware } from '@tanstack/react-start'
import { classifyAgentUserAgent } from '@/lib/agent-user-agent'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { DEFAULT_LLMS_ORIGIN } from '@/lib/seo/llms'
import { generateLlmsTxt } from '@/lib/seo/llms-content'
import { MARKDOWN_TWIN_PAGES, markdownTwinPage } from '@/lib/seo/markdown-twins'
import {
  HOME_LINK_HEADER,
  HOME_MARKDOWN_FRONTMATTER,
  notFoundMarkdown,
  prefersMarkdown,
} from '@/lib/seo/markdown-negotiation'
import { trackServerPageview } from '@/lib/server-analytics'

/**
 * Homepage content negotiation: `Accept: text/markdown` gets the llms.txt hub,
 * and the HTML homepage advertises agent resources in `Link`. Marketing pages
 * get a `.md` twin converted from their rendered HTML, and a 404 asked for as
 * markdown gets a markdown body. AI agents (ChatGPT-User, Claude-User, Cursor,
 * ...) get the markdown version of the homepage and of those pages even when
 * they ask for HTML.
 */
export const markdownNegotiationMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const agent =
    classifyAgentUserAgent(request.headers.get('user-agent')).kind === 'agent'
  const page =
    markdownTwinPage(pathname) ??
    (agent && (MARKDOWN_TWIN_PAGES as readonly string[]).includes(pathname)
      ? pathname
      : null)
  if (page) {
    trackServerPageview(request)
    // Loaded here so the converter stays out of the client bundle.
    const { pageHtmlToMarkdown } = await import('@/lib/seo/page-markdown')
    const twin = await respondWithPrebuiltOrRuntime(
      `${page.slice(1)}.md`,
      'text/markdown; charset=utf-8',
      async () => {
        const origin =
          process.env.NODE_ENV === 'production'
            ? `http://localhost:${process.env.PORT ?? 3000}`
            : request.url
        const response = await fetch(new URL(page, origin), {
          redirect: 'manual',
        })
        if (!response.ok) {
          throw new Response('Not found', {
            status: response.status,
            headers: { 'Cache-Control': 'no-store' },
          })
        }
        return pageHtmlToMarkdown(
          await response.text(),
          DEFAULT_LLMS_ORIGIN,
          page,
        )
      },
    )
    if (page === pathname) twin.headers.set('Vary', 'User-Agent')
    throw twin
  }

  const markdown = prefersMarkdown(request.headers.get('accept'))

  if (pathname !== '/') {
    if (markdown) {
      // TanStack answers 406 unless HTML is acceptable; let the router run so a
      // missing page still reports 404 (RFC 9110 lets us ignore Accept).
      request.headers.append('Accept', 'text/html;q=0.1')
    }
    const result = await next()
    if (!markdown || result.response?.status !== 404) {
      return result
    }
    return {
      ...result,
      response: new Response(notFoundMarkdown(pathname), {
        status: 404,
        headers: {
          'Content-Type': 'text/markdown; charset=utf-8',
          'Cache-Control': 'no-store',
          Vary: 'Accept',
        },
      }),
    }
  }

  if (markdown || agent) {
    trackServerPageview(request)
    const response = await respondWithPrebuiltOrRuntime(
      'llms.txt',
      'text/markdown; charset=utf-8',
      () => generateLlmsTxt(),
    )
    const headers = new Headers(response.headers)
    headers.set('Vary', 'Accept, User-Agent')
    headers.set('Link', HOME_LINK_HEADER)
    throw new Response(HOME_MARKDOWN_FRONTMATTER + (await response.text()), {
      status: response.status,
      headers,
    })
  }

  const result = await next()
  if (!result.response) {
    return result
  }

  const headers = new Headers(result.response.headers)
  headers.append('Vary', 'Accept, User-Agent')
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
