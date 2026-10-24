/**
 * Build a browsable URL for a proxy-rule or deployment domain.
 *
 * Local domains (`localhost`, `*.localhost`, loopback IPs) are served by the
 * local traefik/router without TLS, so they must use `http://` — `https://`
 * simply fails there. Everything else gets `https://`.
 *
 * With an `accessToken` (an unclaimed Zero project), the URL is the owner
 * link: the domain answers 403 until the browser has visited it once.
 */
export function domainUrl(domain: string, accessToken?: string): string {
  const hostname = domain.replace(/:\d+$/, '')
  const isLocal =
    hostname === 'localhost' ||
    hostname.endsWith('.localhost') ||
    hostname === '127.0.0.1' ||
    hostname === '::1'
  const url = `${isLocal ? 'http' : 'https'}://${domain}`
  if (!accessToken) return url
  return `${url}/__appwrite/zero?token=${encodeURIComponent(accessToken)}`
}
