import { createFileRoute } from '@tanstack/react-router'
import { proxyCreateBirdApiKey } from '@/lib/smtp/bird-proxy'

/** Relay for `POST https://{region}.platform.bird.com/v1/api-keys`, email-only keys (SMTP quick setup). */
export const Route = createFileRoute('/_api/bird/api-keys')({
  server: {
    handlers: {
      POST: async ({ request }) => proxyCreateBirdApiKey(request),
    },
  },
})
