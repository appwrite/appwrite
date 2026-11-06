import { createMiddleware } from '@tanstack/react-start'
import { wellKnownAppleAppSiteAssociationResponse } from '@/lib/seo/apple-app-site-association'

/** Serve `/.well-known/apple-app-site-association` before anything can redirect it, which Apple refuses. */
export const appleAppSiteAssociationMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  const response = wellKnownAppleAppSiteAssociationResponse(request, pathname)
  if (response) {
    throw response
  }
  return next()
})
