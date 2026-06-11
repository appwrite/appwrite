import { createFileRoute } from '@tanstack/react-router'
import { generateLlmsFullTxt } from '@/lib/docs/llm'

export const Route = createFileRoute('/llms-full/txt')({
  ssr: true,
  server: {
    handlers: {
      GET: async () => {
        return new Response(generateLlmsFullTxt(), {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
          },
        })
      },
    },
  },
})
