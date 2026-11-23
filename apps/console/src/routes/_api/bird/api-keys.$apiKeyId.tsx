import { createFileRoute } from '@tanstack/react-router'
import { proxyRevokeBirdApiKey } from '@/lib/smtp/bird-proxy'

/** Relay for `POST https://{region}.platform.bird.com/v1/api-keys/:id/revoke` (cleanup after a failed SMTP save). */
export const Route = createFileRoute('/_api/bird/api-keys/$apiKeyId')({
  server: {
    handlers: {
      DELETE: async ({ request, params }) =>
        proxyRevokeBirdApiKey(request, params.apiKeyId.trim()),
    },
  },
})
