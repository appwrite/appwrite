import { createFileRoute } from '@tanstack/react-router'
import { generateLlmsTxt } from '@/lib/docs/llm'
import { respondWithClientStaticFile } from '@/lib/marketing/static-exports'

export const Route = createFileRoute('/llms/txt')({
  ssr: true,
  server: {
    handlers: {
      GET: async () => {
        if (process.env.TSS_PRERENDERING === 'true') {
          return new Response(generateLlmsTxt(), {
            headers: {
              'Content-Type': 'text/markdown; charset=utf-8',
              'Cache-Control': 'public, max-age=3600',
            },
          })
        }

        const fromNested = await respondWithClientStaticFile(
          'llms/txt',
          'text/markdown; charset=utf-8',
        )
        if (fromNested.status !== 404) return fromNested

        return respondWithClientStaticFile(
          'llms.txt',
          'text/markdown; charset=utf-8',
        )
      },
    },
  },
})
