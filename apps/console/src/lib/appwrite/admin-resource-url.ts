/**
 * Console download/preview helpers often append `mode=admin` to an SDK URL.
 * Some SDK builds return a `URL` (or a proxy) instead of a string; calling
 * `.includes` on that value throws `TypeError: a.includes is not a function`.
 */
export function toResourceUrl(value: unknown): string {
  if (typeof value === 'string') return value
  if (value instanceof URL) return value.toString()
  if (
    value != null &&
    typeof value === 'object' &&
    'href' in value &&
    typeof (value as { href: unknown }).href === 'string'
  ) {
    return (value as { href: string }).href
  }
  if (value == null) return ''
  try {
    return String(value)
  } catch {
    return ''
  }
}

/** Append console `mode=admin` without assuming the SDK returned a string. */
export function withAdminMode(url: unknown): string {
  const href = toResourceUrl(url)
  if (!href) return ''
  return href + (href.includes('?') ? '&' : '?') + 'mode=admin'
}
