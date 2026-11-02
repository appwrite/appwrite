import { lookup } from 'node:dns/promises'
import { isIPv4, isIPv6 } from 'node:net'

/**
 * Cover image fields (icon, logos, screenshot, avatar) are caller-supplied on the
 * public generator endpoints, so a remote source is only fetched once the
 * destination is proven to be a public internet address. Bundled asset paths,
 * built-in icons and data URIs never reach this module.
 *
 * Every rejection returns `null`, exactly like an unreachable host, so the
 * rendered cover never reveals whether a target exists.
 */
export const COVER_REMOTE_IMAGE_MAX_BYTES = 8 * 1024 * 1024
export const COVER_REMOTE_IMAGE_TIMEOUT_MS = 5_000
const COVER_REMOTE_IMAGE_MAX_REDIRECTS = 3

/** SVG is excluded: remote markup can pull further resources in when rasterized. */
const COVER_REMOTE_IMAGE_TYPES = [
  'image/png',
  'image/jpeg',
  'image/webp',
  'image/avif',
  'image/gif',
  'image/tiff',
]

const BLOCKED_HOST_SUFFIXES = [
  '.localhost',
  '.local',
  '.internal',
  '.home.arpa',
]

const REDIRECT_STATUSES = [301, 302, 303, 307, 308]

/** Bun accepts TLS options on fetch; the DOM lib types this file uses do not. */
type CoverRemoteImageRequestInit = RequestInit & {
  tls?: { servername?: string }
}

function unbracket(hostname: string): string {
  const host = hostname.trim().toLowerCase()
  if (host.startsWith('[') && host.endsWith(']')) return host.slice(1, -1)
  return host
}

function isBlockedIPv4(address: string): boolean {
  const [a, b, c] = address.split('.').map(Number)
  if (a === 0) return true // "this network"
  if (a === 10) return true // private
  if (a === 127) return true // loopback
  if (a === 100 && b >= 64 && b <= 127) return true // carrier-grade NAT
  if (a === 169 && b === 254) return true // link-local, incl. instance metadata
  if (a === 172 && b >= 16 && b <= 31) return true // private
  if (a === 192 && b === 0 && c === 0) return true // IETF protocol assignments
  if (a === 192 && b === 0 && c === 2) return true // documentation
  if (a === 192 && b === 168) return true // private
  if (a === 198 && (b === 18 || b === 19)) return true // benchmarking
  if (a === 198 && b === 51 && c === 100) return true // documentation
  if (a === 203 && b === 0 && c === 113) return true // documentation
  if (a >= 224) return true // multicast, reserved, broadcast
  return false
}

/** Expand `value` into `count` hextets, resolving a single `::` run. */
function expandHextets(value: string, count: number): number[] | null {
  const parts = value.split('::')
  if (parts.length > 2) return null

  const head = parts[0] ? parts[0].split(':') : []
  const tail = parts.length === 2 && parts[1] ? parts[1].split(':') : []
  if (parts.length === 1 && head.length !== count) return null
  if (head.length + tail.length > count) return null

  const groups =
    parts.length === 1
      ? head
      : [
          ...head,
          ...Array.from(
            { length: count - head.length - tail.length },
            () => '0',
          ),
          ...tail,
        ]

  const hextets = groups.map((group) => Number.parseInt(group || '0', 16))
  if (hextets.some((hextet) => !Number.isInteger(hextet) || hextet > 0xffff)) {
    return null
  }
  return hextets
}

function expandIPv6(address: string): number[] | null {
  const value = address.split('%')[0]
  const lastColon = value.lastIndexOf(':')
  const trailing = value.slice(lastColon + 1)

  if (trailing.includes('.')) {
    if (!isIPv4(trailing)) return null
    const octets = trailing.split('.').map(Number)
    const embedded = [
      (octets[0] << 8) | octets[1],
      (octets[2] << 8) | octets[3],
    ]
    const prefix = expandHextets(value.slice(0, lastColon), 6)
    return prefix ? [...prefix, ...embedded] : null
  }

  return expandHextets(value, 8)
}

function hextetsToIPv4(high: number, low: number): string {
  return [high >> 8, high & 0xff, low >> 8, low & 0xff].join('.')
}

function isBlockedIPv6(address: string): boolean {
  const hextets = expandIPv6(address)
  if (!hextets) return true

  const [first, second] = hextets
  const zeroPrefix = hextets.slice(0, 5).every((hextet) => hextet === 0)

  if (hextets.every((hextet) => hextet === 0)) return true // unspecified
  if (zeroPrefix && hextets[5] === 0) {
    // ::1 loopback and IPv4-compatible addresses
    if (hextets[6] === 0 && hextets[7] <= 1) return true
    return isBlockedIPv4(hextetsToIPv4(hextets[6], hextets[7]))
  }
  if (zeroPrefix && hextets[5] === 0xffff) {
    // IPv4-mapped
    return isBlockedIPv4(hextetsToIPv4(hextets[6], hextets[7]))
  }
  if ((first & 0xffc0) === 0xfe80) return true // link-local
  if ((first & 0xfe00) === 0xfc00) return true // unique local
  if ((first & 0xff00) === 0xff00) return true // multicast
  if (first === 0x64 && second === 0xff9b) {
    // NAT64
    return isBlockedIPv4(hextetsToIPv4(hextets[6], hextets[7]))
  }
  if (first === 0x2002) {
    // 6to4
    return isBlockedIPv4(hextetsToIPv4(second, hextets[2]))
  }
  if (first === 0x2001 && second === 0) return true // Teredo
  return false
}

export function isBlockedCoverImageAddress(address: string): boolean {
  const value = unbracket(address)
  if (isIPv4(value)) return isBlockedIPv4(value)
  if (isIPv6(value)) return isBlockedIPv6(value)
  return true
}

export function isBlockedCoverImageHost(hostname: string): boolean {
  const host = unbracket(hostname)
  if (!host) return true
  if (host === 'localhost') return true
  if (BLOCKED_HOST_SUFFIXES.some((suffix) => host.endsWith(suffix))) return true
  if (isIPv4(host) || isIPv6(host)) return isBlockedCoverImageAddress(host)
  return false
}

/** Public https image URL, or `null` when the source may not be requested. */
export function resolveCoverRemoteImageUrl(source: string): URL | null {
  let url: URL
  try {
    url = new URL(source.trim())
  } catch {
    return null
  }

  if (url.protocol !== 'https:') return null
  if (url.username || url.password) return null
  if (isBlockedCoverImageHost(url.hostname)) return null
  return url
}

async function resolveHostAddresses(hostname: string): Promise<string[]> {
  const host = unbracket(hostname)
  if (isIPv4(host) || isIPv6(host)) return [host]

  try {
    const records = await lookup(host, { all: true, verbatim: true })
    return records.map((record) => record.address)
  } catch {
    return []
  }
}

async function discard(response: Response): Promise<void> {
  try {
    await response.body?.cancel()
  } catch {
    // body already consumed or closed
  }
}

async function readCoverRemoteImageBody(
  response: Response,
): Promise<Buffer | null> {
  const type = (response.headers.get('content-type') ?? '')
    .split(';')[0]
    .trim()
    .toLowerCase()
  const declaredBytes = Number(response.headers.get('content-length'))

  if (
    !response.ok ||
    !COVER_REMOTE_IMAGE_TYPES.includes(type) ||
    declaredBytes > COVER_REMOTE_IMAGE_MAX_BYTES
  ) {
    await discard(response)
    return null
  }

  const reader = response.body?.getReader()
  if (!reader) return null

  const chunks: Uint8Array[] = []
  let bytes = 0

  try {
    for (;;) {
      const { done, value } = await reader.read()
      if (done) break
      bytes += value.byteLength
      if (bytes > COVER_REMOTE_IMAGE_MAX_BYTES) {
        await reader.cancel()
        return null
      }
      chunks.push(value)
    }
  } catch {
    return null
  }

  return bytes ? Buffer.concat(chunks) : null
}

/**
 * Request the checked address itself, keeping the original authority for the
 * `Host` header and for TLS, so a name that resolves again to a private address
 * between the check and the connection cannot redirect us inward.
 */
function pinToAddress(url: URL, address: string): URL {
  const pinned = new URL(url)
  pinned.hostname = isIPv6(address) ? `[${address}]` : address
  return pinned
}

/**
 * Fetch a remote cover image, re-checking the destination on every redirect hop.
 * A failed pinned request is not retried unpinned: no image beats an unguarded
 * one.
 */
export async function fetchCoverRemoteImageBuffer(
  source: string,
): Promise<Buffer | null> {
  let url = resolveCoverRemoteImageUrl(source)
  if (!url) return null

  for (let hop = 0; hop <= COVER_REMOTE_IMAGE_MAX_REDIRECTS; hop += 1) {
    const addresses = await resolveHostAddresses(url.hostname)
    if (!addresses.length || addresses.some(isBlockedCoverImageAddress)) {
      return null
    }

    // One deadline for the hop, so trying each checked address cannot stretch
    // the time a caller holds a render worker open.
    const signal = AbortSignal.timeout(COVER_REMOTE_IMAGE_TIMEOUT_MS)
    let response: Response | null = null

    const init: CoverRemoteImageRequestInit = {
      redirect: 'manual',
      signal,
      headers: {
        Accept: COVER_REMOTE_IMAGE_TYPES.join(', '),
        Host: url.host,
      },
      tls: { servername: url.hostname },
    }

    for (const address of addresses) {
      try {
        response = await fetch(pinToAddress(url, address), init)
        break
      } catch {
        response = null
      }
    }

    if (!response) return null

    if (!REDIRECT_STATUSES.includes(response.status)) {
      return readCoverRemoteImageBody(response)
    }

    const location = response.headers.get('location')
    await discard(response)
    if (!location) return null

    let hopUrl: string
    try {
      hopUrl = new URL(location, url).href
    } catch {
      return null
    }

    const next = resolveCoverRemoteImageUrl(hopUrl)
    if (!next) return null
    url = next
  }

  return null
}
