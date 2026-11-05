import { createFileRoute } from '@tanstack/react-router'
import { proxyCreateResendApiKey } from '@/lib/smtp/resend-proxy'

/** Relay for `POST https://api.resend.com/api-keys`, sending-only keys (SMTP quick setup). */
export const Route = createFileRoute('/_api/resend/api-keys')({
  server: {
    handlers: {
      POST: async ({ request }) => proxyCreateResendApiKey(request),
    },
  },
})
