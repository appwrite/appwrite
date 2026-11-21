import { createFileRoute } from '@tanstack/react-router'
import { proxyListBirdSenders } from '@/lib/smtp/bird-proxy'

/** Relay for `GET https://{region}.platform.bird.com/v1/sms/senders` (SMS one-click setup). */
export const Route = createFileRoute('/_api/bird/senders')({
  server: {
    handlers: {
      GET: async ({ request }) => proxyListBirdSenders(request),
    },
  },
})
