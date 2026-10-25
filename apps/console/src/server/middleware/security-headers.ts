import { createMiddleware } from '@tanstack/react-start'
import { withDocumentSecurityHeaders } from '@/lib/security-headers'

/**
 * Framing, MIME-sniffing and referrer headers on every HTML page the TanStack
 * handler renders. See `@/lib/security-headers`.
 */
export const securityHeadersMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ next }) => {
  const result = await next()
  if (!result.response) {
    return result
  }
  return {
    ...result,
    response: withDocumentSecurityHeaders(result.response),
  }
})
