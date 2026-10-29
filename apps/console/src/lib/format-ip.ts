import { truncateMiddle } from '@/lib/utils'

/**
 * Trims whitespace, unwraps `[addr]` form, and strips an IPv6 zone id (`fe80::1%en0`).
 */
export function normalizeIpForDisplay(raw: string | null | undefined): string {
  if (raw == null) return ''
  let s = raw.trim()
  if (!s) return ''
  if (s.startsWith('[') && s.endsWith(']')) {
    const inner = s.slice(1, -1).trim()
    if (inner.includes(':')) s = inner
  }
  const pct = s.indexOf('%')
  if (pct !== -1 && s.includes(':')) {
    s = s.slice(0, pct)
  }
  return s
}

function isIpv6(ip: string): boolean {
  return normalizeIpForDisplay(ip).includes(':')
}

/**
 * IPv6: shortens long strings with middle ellipsis after normalizing.
 * IPv4: returns normalized trimmed string unchanged.
 */
export function formatIpForDisplay(
  ip: string | null | undefined,
  maxLen = 36,
): string | null {
  if (ip == null || ip.trim() === '') return null
  const s = normalizeIpForDisplay(ip)
  if (!s) return null
  if (!isIpv6(s)) return s
  return s.length <= maxLen ? s : truncateMiddle(s, maxLen)
}
