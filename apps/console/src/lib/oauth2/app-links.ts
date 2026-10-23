/** Schemes a link from an app's publisher may use. */
const APP_LINK_SCHEMES = new Set(['http:', 'https:', 'mailto:'])

/**
 * Href for a link an app's publisher supplied (homepage, terms, privacy
 * policy, support, data deletion), or undefined when it is not a web or mail
 * link, so a stored `javascript:` or `data:` value never becomes clickable.
 */
export function getAppLinkHref(
  url: string | null | undefined,
): string | undefined {
  const value = url?.trim()
  if (!value) return undefined
  try {
    return APP_LINK_SCHEMES.has(new URL(value).protocol) ? value : undefined
  } catch {
    return undefined
  }
}
