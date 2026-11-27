import { z } from 'zod'
import { isPreLaunchDocumentRequest } from '@/lib/pre-launch'
import { parseSearch } from '@/lib/router-search'
import { isLocalDevelopmentHost } from '@/lib/sentry/environment-shared'

/**
 * A provider's shared SSO URL (`/sso?provider=…`, from Settings → Single
 * sign-on → Copy SSO URL): what the page and the server both need to send its
 * visitor to the identity provider. The server imports this before any page
 * loads, so it stays free of the Console's client code.
 */

/** What `/sso` reads from its URL. */
export const ssoSearchSchema = z.object({
  /**
   * Set by the server when a sign-in fails: `{"message": …, "type": …}` as JSON,
   * which the router already parses into an object. Only its type picks what
   * the page says (see `ssoFailureMessage`).
   */
  error: z.unknown().optional(),
  /** Console path to return to after signing in; checked by `ssoReturnPath`. */
  redirect: z.string().optional().catch(undefined),
  /**
   * A provider's shared SSO URL: the sign-in starts at once, with no email to
   * type and nothing to pick.
   */
  provider: z.coerce.string().optional(),
  /** Set when an organization that requires single sign-on sent the member here. */
  required: z.stringbool().optional(),
})

export type SSOSearch = z.infer<typeof ssoSearchSchema>

/** Whether `/sso` was opened with the `?error=` of a failed sign-in. */
export function hasSSOError(error: unknown): boolean {
  return error !== undefined && error !== null && error !== ''
}

/**
 * The provider whose sign-in starts at once. A failed sign-in comes back
 * without `provider`, so this never loops; one that still names a provider
 * shows its failure instead of starting again.
 */
export function ssoSharedProvider(
  search: Pick<SSOSearch, 'provider' | 'error'>,
): string | undefined {
  return search.provider && !hasSSOError(search.error)
    ? search.provider
    : undefined
}

/**
 * Where the browser goes to sign in through `providerId`. Without `success`,
 * the API lands the person in the provider's organization.
 */
export function ssoSignInURL(
  endpoint: string,
  project: string,
  providerId: string,
  success: string | undefined,
  failure: string,
): string {
  const url = new URL(endpoint + '/account/sessions/sso')
  url.searchParams.set('project', project)
  url.searchParams.set('providerId', providerId)
  if (success) url.searchParams.set('success', success)
  url.searchParams.set('failure', failure)
  return url.toString()
}

/**
 * The origin the browser asked for. Behind the proxies in front of the Console
 * the request itself arrives over plain HTTP from another host, so their
 * forwarded headers come first; without them only a local host is taken to be
 * plain HTTP.
 */
export function requestOrigin(request: Request): string | null {
  const first = (name: string) =>
    request.headers.get(name)?.split(',')[0]?.trim() ?? ''
  try {
    const url = new URL(request.url)
    const host = first('x-forwarded-host') || url.host
    const forwarded = first('x-forwarded-proto').toLowerCase()
    const protocol =
      forwarded === 'http' || forwarded === 'https'
        ? `${forwarded}:`
        : isLocalDevelopmentHost(new URL(`http://${host}`).hostname)
          ? url.protocol
          : 'https:'
    return new URL(`${protocol}//${host}`).origin
  } catch {
    return null
  }
}

/**
 * Where the server sends a browser that opens a shared SSO URL, so it reaches
 * the identity provider without loading the Console first. Null when the page
 * is needed instead:
 *
 * - no provider, or a failed sign-in to show;
 * - a page to return to, which the page checks itself (`ssoReturnPath`);
 * - not a browser's navigation, such as a link preview.
 *
 * The URL is the one the page builds for the same visit (`ssoSignInURLs`).
 */
export function ssoSharedURLRedirect(
  request: Request,
  endpoint: string,
): string | null {
  if (request.method !== 'GET' || !isPreLaunchDocumentRequest(request)) {
    return null
  }
  let url: URL
  try {
    url = new URL(request.url)
  } catch {
    return null
  }
  if (url.pathname.replace(/\/+$/, '') !== '/sso') return null

  const search = ssoSearchSchema.safeParse(parseSearch(url.search))
  if (!search.success) return null
  const provider = ssoSharedProvider(search.data)
  if (!provider || search.data.redirect) return null

  const origin = requestOrigin(request)
  if (!origin) return null
  return ssoSignInURL(
    endpoint,
    'console',
    provider,
    undefined,
    new URL('/sso', origin).toString(),
  )
}
