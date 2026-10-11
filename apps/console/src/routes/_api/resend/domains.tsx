import { createFileRoute } from '@tanstack/react-router'
import { proxyResendRequest } from '@/lib/smtp/resend-proxy'

/** Relay for `GET https://api.resend.com/domains` (SMTP quick setup). */
export const Route = createFileRoute('/_api/resend/domains')({
  server: {
    handlers: {
      GET: async ({ request }) =>
        proxyResendRequest(request, {
          method: 'GET',
          path: '/domains',
          forwardQuery: true,
        }),
    },
  },
})
