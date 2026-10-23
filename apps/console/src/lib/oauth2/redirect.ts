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
 * Schemes the browser runs or renders as a document instead of handing off
 * to a site or an app. No OAuth2 client redirect needs one.
 */
const DOCUMENT_SCHEMES = new Set([
  'javascript:',
  'data:',
  'vbscript:',
  'blob:',
  'file:',
  'filesystem:',
  'about:',
])

/**
 * `uri` when the consent flow may navigate to it (a web URL or a native app
 * link such as `cursor://`), otherwise undefined.
 */
export function getNavigableRedirect(
  uri: string | undefined,
): string | undefined {
  if (!uri) return undefined
  try {
    return DOCUMENT_SCHEMES.has(new URL(uri).protocol) ? undefined : uri
  } catch {
    return undefined
  }
}
