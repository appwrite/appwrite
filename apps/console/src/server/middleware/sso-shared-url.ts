import { createMiddleware } from '@tanstack/react-start'
import { getProfileFeaturesForCookieHeader } from '@/lib/console-profiles'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { resolveAppwriteEndpointFallback } from '@/lib/runtime-config-shared'
import { ssoSharedURLRedirect } from '@/lib/sso-shared-url'

/** The Console's own API endpoint, as `getApiEndpoint()` gives it without a region. */
function consoleEndpoint(): string | null {
  const config = getRuntimeConfig()
  try {
    const url = new URL(
      config.appwriteEndpoint ||
        resolveAppwriteEndpointFallback(config.consoleProfile),
    )
    return `${url.protocol}//${url.host}/v1`
  } catch {
    return null
  }
}

/**
 * A provider's shared SSO URL (`/sso?provider=…`) goes straight to the identity
 * provider: the browser is redirected before any of the Console loads, instead
 * of showing the sign-in page until its own redirect starts. Everything else on
 * `/sso` is the page's (see `ssoSharedURLRedirect`), which still starts the
 * sign-in itself when this redirect did not apply.
 *
 * The answer follows the debug profile cookie, like the page's own gate.
 */
export const ssoSharedURLMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, next }) => {
  if (process.env.TSS_PRERENDERING === 'true') return next()

  const endpoint = consoleEndpoint()
  const location = endpoint ? ssoSharedURLRedirect(request, endpoint) : null
  if (!location) return next()
  // Where the Console has no single sign-on (self-hosted), the page sends the
  // visitor to the sign-in page.
  if (!getProfileFeaturesForCookieHeader(request.headers.get('cookie')).sso) {
    return next()
  }

  throw new Response(null, {
    status: 302,
    headers: {
      Location: location,
      Vary: 'Cookie',
      'Cache-Control': 'private, no-store',
    },
  })
})
