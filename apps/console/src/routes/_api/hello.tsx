import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/_api/hello')({
  server: {
    handlers: {
      GET: async ({ request }) => {
        return new Response('Hello, World! from ' + request.url)
      },
    },
  },
})
