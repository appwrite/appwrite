import { lookup } from 'node:dns/promises'
import { isIP } from 'node:net'

/**
 * SSRF guard for the server-side cover renderer. A remote cover image URL is
 * attacker-controlled (the `screenshot`/`logo`/`icon` fields), and the renderer
 * fetches it server-side, so an internal or link-local target would let a caller
 * probe the deployment's network and read back the response bytes. Only public
 * unicast http(s) destinations are allowed through.
 */

/**
 * True for addresses a public cover image must never resolve to: loopback,
 * private, link-local (incl. the 169.254.169.254 cloud metadata endpoint),
 * carrier-grade NAT, unspecified, multicast and reserved ranges, for both IPv4
 * and IPv6 (including IPv4-mapped IPv6).
 */
export function isPrivateOrReservedAddress(ip: string): boolean {
  const family = isIP(ip)
  if (family === 4) return isPrivateOrReservedV4(ip)
  if (family === 6) return isPrivateOrReservedV6(ip)
  // Not a bare IP literal: treat as unsafe rather than guess.
  return true
}

function isPrivateOrReservedV4(ip: string): boolean {
  const octets = ip.split('.').map((part) => Number(part))
  if (octets.length !== 4 || octets.some((n) => !Number.isInteger(n) || n < 0 || n > 255)) {
    return true
  }
  const [a, b] = octets
  if (a === 0) return true // 0.0.0.0/8 "this network"
  if (a === 10) return true // 10.0.0.0/8 private
  if (a === 127) return true // 127.0.0.0/8 loopback
  if (a === 169 && b === 254) return true // 169.254.0.0/16 link-local (metadata)
  if (a === 172 && b >= 16 && b <= 31) return true // 172.16.0.0/12 private
  if (a === 192 && b === 168) return true // 192.168.0.0/16 private
  if (a === 100 && b >= 64 && b <= 127) return true // 100.64.0.0/10 CGNAT
  if (a === 198 && (b === 18 || b === 19)) return true // 198.18.0.0/15 benchmark
  if (a >= 224) return true // 224.0.0.0/4 multicast + 240.0.0.0/4 reserved
  return false
}

function isPrivateOrReservedV6(ip: string): boolean {
  const address = ip.toLowerCase().split('%')[0] // drop any zone id
  if (address === '::1' || address === '::') return true // loopback / unspecified

  // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible: classify the embedded v4.
  const mapped = address.match(/^(?:::ffff:|::)(\d+\.\d+\.\d+\.\d+)$/)
  if (mapped) return isPrivateOrReservedV4(mapped[1])

  const firstHextet = address.split(':')[0]
  const high = parseInt(firstHextet || '0', 16)
  if ((high & 0xfe00) === 0xfc00) return true // fc00::/7 unique local
  if ((high & 0xffc0) === 0xfe80) return true // fe80::/10 link-local
  if ((high & 0xff00) === 0xff00) return true // ff00::/8 multicast
  return false
}

/**
 * Resolves the URL's host and throws unless it is an http(s) URL that resolves
 * only to public unicast addresses. Every resolved address is checked so a name
 * with one internal A/AAAA record cannot slip through.
 */
export async function assertPublicCoverImageUrl(href: string): Promise<void> {
  let url: URL
  try {
    url = new URL(href)
  } catch {
    throw new Error('Cover image URL is not a valid URL.')
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error('Cover image URL must be http or https.')
  }

  const hostname = url.hostname.replace(/^\[|\]$/g, '') // strip IPv6 brackets
  if (isIP(hostname) !== 0) {
    if (isPrivateOrReservedAddress(hostname)) {
      throw new Error('Cover image URL resolves to a non-public address.')
    }
    return
  }

  const addresses = await lookup(hostname, { all: true })
  if (addresses.length === 0 || addresses.some(({ address }) => isPrivateOrReservedAddress(address))) {
    throw new Error('Cover image URL resolves to a non-public address.')
  }
}
