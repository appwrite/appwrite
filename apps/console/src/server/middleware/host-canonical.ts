import { createMiddleware } from '@tanstack/react-start'
import { getCanonicalHostRedirectResponse } from '@/lib/seo/indexing'

/**
 * Permanent (301) redirects from www.appwrite.io and new.appwrite.io to
 * appwrite.io, preserving path and query string.
 */
export const hostCanonicalMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, next }) => {
  // Prerender fetches must reach the page HTML; a redirect fails the build.
  if (process.env.TSS_PRERENDERING === 'true') {
    return next()
  }

  const redirect = getCanonicalHostRedirectResponse(request)
  if (redirect) {
    throw redirect
  }

  return next()
})
