import { createFileRoute } from '@tanstack/react-router'
import { answerAsk, preflightAsk } from '@/lib/nlweb/ask'

export const Route = createFileRoute('/_api/ask')({
  server: {
    handlers: {
      POST: ({ request }) => answerAsk(request),
      OPTIONS: () => preflightAsk(),
    },
  },
})
