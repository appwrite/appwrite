import { createFileRoute } from '@tanstack/react-router'
import { generateLlmsFullTxt } from '@/lib/docs/llm'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithClientStaticFile } from '@/lib/marketing/static-exports'
import { trackServerPageview } from '@/lib/server-analytics'

// The full docs dump is static per build; generate at most once per server.
let cachedLlmsFullTxt: Promise<string> | null = null

function getLlmsFullTxt(): Promise<string> {
  cachedLlmsFullTxt ??= generateLlmsFullTxt()
  return cachedLlmsFullTxt
}

/** Canonical llms-full.txt endpoint (https://llmstxt.org). */
export const Route = createFileRoute('/llms-full.txt')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)

        // Prefer the prebuilt export when available (production builds).
        if (process.env.NODE_ENV === 'production') {
          const prebuilt = await respondWithClientStaticFile(
            'llms-full.txt',
            'text/markdown; charset=utf-8',
          )
          if (prebuilt.status !== 404) return prebuilt
        }

        return new Response(await getLlmsFullTxt(), {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
          },
        })
      },
    },
  },
})
