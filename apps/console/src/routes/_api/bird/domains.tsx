import { createFileRoute } from '@tanstack/react-router'
import { proxyListBirdDomains } from '@/lib/smtp/bird-proxy'

/** Relay for `GET https://{region}.platform.bird.com/v1/email/domains` (SMTP quick setup). */
export const Route = createFileRoute('/_api/bird/domains')({
  server: {
    handlers: {
      GET: async ({ request }) => proxyListBirdDomains(request),
    },
  },
})
