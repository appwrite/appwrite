/**
 * Decide whether an OAuth2 redirect target will navigate the browser away
 * (a normal web URL) or hand off to the operating system (a native deep link
 * like `cursor://` or a loopback custom scheme).
 *
 * Web redirects (`http:` / `https:`) leave the tab, so the consent flow just
 * navigates to them. Native redirects are handled by the OS - the browser
 * stays put - so the flow instead shows the outcome card with an "Open app"
 * retry button for users who dismissed the OS prompt.
 */
export function isWebRedirect(uri: string): boolean {
  try {
    const protocol = new URL(uri).protocol
    return protocol === 'http:' || protocol === 'https:'
  } catch {
    return false
  }
}

/**
 * Why an OAuth2 redirect URI would be rejected, or null when it is allowed:
 * web URIs must be https (http only on a loopback host), native schemes pass,
 * and no URI may carry a fragment.
 */
export function redirectUriError(uri: string): string | null {
  let url: URL
  try {
    url = new URL(uri)
  } catch {
    return 'Enter a valid URL'
  }
  if (url.hash || uri.includes('#'))
    return 'Redirect URIs cannot contain a fragment (#)'
  if (
    url.protocol === 'http:' &&
    !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
  ) {
    return 'Use https, or http only on localhost'
  }
  return null
}
