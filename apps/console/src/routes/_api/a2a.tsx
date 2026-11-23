import { createFileRoute } from '@tanstack/react-router'
import { answerA2a, preflightA2a } from '@/lib/a2a/agent'

export const Route = createFileRoute('/_api/a2a')({
  server: {
    handlers: {
      POST: ({ request }) => answerA2a(request),
      OPTIONS: () => preflightA2a(),
    },
  },
})
