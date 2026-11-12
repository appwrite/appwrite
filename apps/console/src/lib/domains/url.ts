/**
 * Build a browsable URL for a proxy-rule or deployment domain.
 *
 * Local domains (`localhost`, `*.localhost`, loopback IPs) are served by the
 * local traefik/router without TLS, so they must use `http://` — `https://`
 * simply fails there. Everything else gets `https://`.
 */
export function domainUrl(domain: string): string {
  const hostname = domain.replace(/:\d+$/, '')
  const isLocal =
    hostname === 'localhost' ||
    hostname.endsWith('.localhost') ||
    hostname === '127.0.0.1' ||
    hostname === '::1'
  return `${isLocal ? 'http' : 'https'}://${domain}`
}

/**
 * The owner link of an unclaimed Zero project's domain: the domain answers 403
 * until the browser has opened it once. The edge takes the token, sets a
 * cookie and redirects to `/`, so it does not stay in the page URL. Use it only
 * as the target of a link the owner clicks, never in text, copies or QR codes.
 */
export function ownerLinkUrl(domain: string, accessToken: string): string {
  return `${domainUrl(domain)}/__appwrite/zero?token=${encodeURIComponent(accessToken)}`
}

/**
 * Where a link that opens a domain should point. Only the owner of a project
 * that is still unclaimed gets the owner link; everyone else, and a claimed
 * or deleted project, gets the plain domain. Pass `zero` only when the console
 * is signed in as a Zero owner.
 */
export function visitUrl(
  domain: string,
  zero: { status: string; accessToken?: string } | undefined,
): string {
  const open = zero?.status === 'active' || zero?.status === 'expired'
  return open && zero?.accessToken
    ? ownerLinkUrl(domain, zero.accessToken)
    : domainUrl(domain)
}
