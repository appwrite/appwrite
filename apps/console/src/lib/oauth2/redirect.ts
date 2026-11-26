import { translate } from '@/lib/i18n/translate'

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
 * Why an OAuth2 redirect URI would be rejected, or null when it is allowed.
 * Mirrors the server's redirect URI validator: https on any host, http on a
 * loopback host, or an authority-less private-use scheme containing a dot
 * (`com.example.app:/oauth`, RFC 8252 §7.1); never a fragment.
 */
export function redirectUriError(uri: string): string | null {
  if (uri.includes('#'))
    return translate('Redirect URIs cannot contain a fragment (#)')
  let url: URL
  try {
    url = new URL(uri)
  } catch {
    return translate('Enter a valid URL')
  }
  const scheme = url.protocol.slice(0, -1)
  const isPrivateUse =
    scheme.includes('.') && !uri.slice(scheme.length + 1).startsWith('//')
  if (isPrivateUse || scheme === 'https') return null
  if (
    scheme === 'http' &&
    ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
  ) {
    return null
  }
  return translate(
    'Use https, http on localhost, or a reverse-domain scheme like com.example.app:/callback',
  )
}
