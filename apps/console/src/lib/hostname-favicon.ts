/**
 * Normalize a hostname label for favicon lookup via the Avatars API.
 * Returns null for empty or unknown values.
 */
export function normalizeHostnameForFavicon(hostname: string): string | null {
  const trimmed = hostname.trim()
  if (!trimmed || trimmed === 'Unknown') return null

  const withoutScheme = trimmed.replace(/^https?:\/\//i, '').split('/')[0] ?? ''
  if (!withoutScheme) return null

  if (withoutScheme.startsWith('[')) {
    const end = withoutScheme.indexOf(']')
    if (end !== -1) return withoutScheme.slice(0, end + 1)
  }

  return withoutScheme.split(':')[0] ?? withoutScheme
}
