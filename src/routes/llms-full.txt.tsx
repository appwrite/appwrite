import { createFileRoute } from '@tanstack/react-router'
import { generateLlmsFullTxt } from '@/lib/docs/llm'
import { respondWithClientStaticFile } from '@/lib/marketing/static-exports'

export const Route = createFileRoute('/llms-full/txt')({
  ssr: true,
  server: {
    handlers: {
      GET: async () => {
        if (process.env.TSS_PRERENDERING === 'true') {
          return new Response(await generateLlmsFullTxt(), {
            headers: {
              'Content-Type': 'text/markdown; charset=utf-8',
              'Cache-Control': 'public, max-age=3600',
            },
          })
        }

        const fromNested = await respondWithClientStaticFile(
          'llms-full/txt',
          'text/markdown; charset=utf-8',
        )
        if (fromNested.status !== 404) return fromNested

        return respondWithClientStaticFile(
          'llms-full.txt',
          'text/markdown; charset=utf-8',
        )
      },
    },
  },
})
