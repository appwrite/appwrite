import { createFileRoute } from '@tanstack/react-router'
import { jsonError, proxyResendRequest } from '@/lib/smtp/resend-proxy'

const API_KEY_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/

/** Relay for `DELETE https://api.resend.com/api-keys/:id` (cleanup after a failed SMTP save). */
export const Route = createFileRoute('/_api/resend/api-keys/$apiKeyId')({
  server: {
    handlers: {
      DELETE: async ({ request, params }) => {
        const apiKeyId = params.apiKeyId.trim()
        if (!API_KEY_ID_PATTERN.test(apiKeyId)) {
          return jsonError(400, 'validation_error', 'Invalid API key id')
        }
        return proxyResendRequest(request, {
          method: 'DELETE',
          path: `/api-keys/${encodeURIComponent(apiKeyId)}`,
        })
      },
    },
  },
})
