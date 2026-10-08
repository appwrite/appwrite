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
