import { createMiddleware } from '@tanstack/react-start'
import { getCanonicalHostRedirectResponse } from '@/lib/seo/indexing'

/**
 * Permanent (301) redirects from alias hosts, preserving path and query:
 * www.appwrite.io and new.appwrite.io to appwrite.io, and
 * new.staging.appwrite.io to staging.appwrite.io.
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
