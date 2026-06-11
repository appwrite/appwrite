import { createFileRoute } from '@tanstack/react-router'
import { generateLlmsTxt } from '@/lib/docs/llm'

export const Route = createFileRoute('/llms/txt')({
  ssr: true,
  server: {
    handlers: {
      GET: async () => {
        return new Response(generateLlmsTxt(), {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
          },
        })
      },
    },
  },
})
