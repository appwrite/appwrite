import { createMiddleware } from '@tanstack/react-start'
import { wellKnownSecurityTxtResponse } from '@/lib/seo/security-txt'

/** Serve RFC 9116 `/.well-known/security.txt` before other gates. */
export const securityTxtMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const response = wellKnownSecurityTxtResponse(request, pathname)
  if (response) {
    throw response
  }
  return next()
})
