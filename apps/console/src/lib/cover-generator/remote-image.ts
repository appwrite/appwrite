import { lookup } from 'node:dns/promises'
import { checkServerIdentity, type PeerCertificate } from 'node:tls'
import sharp from 'sharp'

/**
 * Guarded loader for the remote images the public cover and diagram generators
 * accept as `icon`, `logoLeft`, `logoRight`, `terminalIcon` and `screenshot`.
 *
 * The generator endpoints are unauthenticated, so an unguarded `fetch()` of a
 * caller-supplied URL lets anyone use the renderer as a proxy into the network
 * the server sits in. Every request here is therefore limited to public hosts
 * on default HTTP ports, redirects are followed one validated hop at a time,
 * and the response must be a bounded, decodable image.
 *
 * The request is pinned to the address that was validated, so a record flipped
 * between the lookup and the connection cannot redirect it into the private
 * network.
 */

export const COVER_REMOTE_IMAGE_TIMEOUT_MS = 5_000
export const COVER_REMOTE_IMAGE_MAX_BYTES = 8 * 1024 * 1024
const COVER_REMOTE_IMAGE_MAX_REDIRECTS = 3

const COVER_REMOTE_IMAGE_CONTENT_TYPES = [
  'image/avif',
  'image/gif',
  'image/jpeg',
  'image/png',
  'image/svg+xml',
  'image/webp',
]

// What a browser asks for. A list of concrete image types with no wildcard
// makes some CDNs answer 406, and the header is only a hint anyway: the
// response type is checked on the way in.
const COVER_REMOTE_IMAGE_ACCEPT =
  'image/avif,image/webp,image/png,image/svg+xml,*/*;q=0.8'

/** Suffixes that only ever name hosts inside a private network. */
const PRIVATE_HOST_SUFFIXES = [
  '.internal',
  '.local',
  '.localhost',
  '.home.arpa',
]

/** Resolves a hostname to every address it would be contacted on. */
export type CoverRemoteImageLookup = (hostname: string) => Promise<string[]>

export type CoverRemoteImageOptions = {
  lookup?: CoverRemoteImageLookup
}

function parseIpv4(value: string): number[] | null {
  const parts = value.split('.')
  if (parts.length !== 4) return null

  const octets: number[] = []
  for (const part of parts) {
    if (!part.length || part.length > 3) return null
    if (part.length > 1 && part.startsWith('0')) return null
    let octet = 0
    for (const character of part) {
      if (character < '0' || character > '9') return null
      octet = octet * 10 + (character.charCodeAt(0) - 48)
    }
    if (octet > 255) return null
    octets.push(octet)
  }

  return octets
}

function parseHexGroup(part: string): number | null {
  if (!part.length || part.length > 4) return null

  let group = 0
  for (const character of part.toLowerCase()) {
    const digit =
      character >= '0' && character <= '9'
        ? character.charCodeAt(0) - 48
        : character >= 'a' && character <= 'f'
          ? character.charCodeAt(0) - 87
          : null
    if (digit === null) return null
    group = group * 16 + digit
  }

  return group
}

function parseIpv6Section(section: string): number[] | null {
  if (!section) return []

  const parts = section.split(':')
  const groups: number[] = []
  for (const [index, part] of parts.entries()) {
    if (part.includes('.')) {
      // An embedded IPv4 address is only valid as the final part.
      if (index !== parts.length - 1) return null
      const octets = parseIpv4(part)
      if (!octets) return null
      groups.push((octets[0] << 8) | octets[1], (octets[2] << 8) | octets[3])
      continue
    }

    const group = parseHexGroup(part)
    if (group === null) return null
    groups.push(group)
  }

  return groups
}

function parseIpv6(value: string): number[] | null {
  if (!value.includes(':') || value.includes('%')) return null

  const sections = value.split('::')
  if (sections.length > 2) return null

  const head = parseIpv6Section(sections[0])
  if (!head) return null
  if (sections.length === 1) return head.length === 8 ? head : null

  const tail = parseIpv6Section(sections[1])
  if (!tail) return null
  if (head.length + tail.length > 7) return null

  return [...head, ...Array(8 - head.length - tail.length).fill(0), ...tail]
}

function isPublicIpv4(octets: number[]): boolean {
  const [first, second, third] = octets

  if (first === 0 || first === 10 || first === 127) return false
  if (first >= 224) return false // multicast, reserved and broadcast
  if (first === 100 && second >= 64 && second <= 127) return false // CGNAT
  if (first === 169 && second === 254) return false // link-local
  if (first === 172 && second >= 16 && second <= 31) return false
  if (first === 192 && second === 168) return false
  if (first === 192 && second === 0 && (third === 0 || third === 2))
    return false
  if (first === 192 && second === 88 && third === 99) return false // 6to4 relay
  if (first === 198 && (second === 18 || second === 19)) return false // benchmarking
  if (first === 198 && second === 51 && third === 100) return false // documentation
  if (first === 203 && second === 0 && third === 113) return false // documentation

  return true
}

function isPublicIpv6(groups: number[]): boolean {
  const [first, second] = groups

  // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d) addresses
  // reach the embedded IPv4 destination, so classify that instead. `::` and
  // `::1` land here too, as 0.0.0.0 and 0.0.0.1.
  if (
    groups.slice(0, 5).every((group) => group === 0) &&
    (groups[5] === 0xffff || groups[5] === 0)
  ) {
    return isPublicIpv4([
      groups[6] >> 8,
      groups[6] & 0xff,
      groups[7] >> 8,
      groups[7] & 0xff,
    ])
  }

  // Only global unicast is routable on the public internet. Allowing that one
  // block and subtracting its special-use ranges rejects by default: NAT64,
  // unique local, link-local, multicast and every unassigned or reserved
  // prefix (`0100::/64`, `4000::/3`, …) all fall outside it.
  if ((first & 0xe000) !== 0x2000) return false

  if (first === 0x2001 && second === 0x0000) return false // Teredo
  if (first === 0x2001 && (second & 0xfff0) === 0x0020) return false // ORCHIDv2
  if (first === 0x2001 && second === 0x0db8) return false // documentation
  if (first === 0x2002) return false // 6to4
  if (first === 0x3fff && (second & 0xf000) === 0) return false // documentation

  return true
}

/** True only for addresses that are routable on the public internet. */
export function isPublicCoverImageAddress(address: string): boolean {
  const ipv4 = parseIpv4(address)
  if (ipv4) return isPublicIpv4(ipv4)

  const ipv6 = parseIpv6(address)
  if (ipv6) return isPublicIpv6(ipv6)

  return false
}

function stripIpv6Brackets(hostname: string): string {
  return hostname.startsWith('[') && hostname.endsWith(']')
    ? hostname.slice(1, -1)
    : hostname
}

function isPublicHostname(hostname: string): boolean {
  const host = hostname.endsWith('.') ? hostname.slice(0, -1) : hostname
  if (!host) return false
  // Public DNS names are always dotted; a bare label is a network-local name.
  if (!host.includes('.')) return false
  return !PRIVATE_HOST_SUFFIXES.some((suffix) => host.endsWith(suffix))
}

/**
 * Parses a caller-supplied remote image URL, rejecting everything that is not
 * a plain HTTP(S) request to a public host on its scheme's default port.
 * Address literals are classified here; named hosts are resolved in
 * {@link resolveCoverImageAddress}.
 */
export function parseCoverRemoteImageUrl(
  source: string | undefined,
): URL | null {
  const value = source?.trim()
  if (!value) return null

  let url: URL
  try {
    url = new URL(value)
  } catch {
    return null
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') return null
  if (url.username || url.password) return null
  // An explicit port is only ever the one the scheme would have used anyway;
  // `https://host:80` must not slip through the http default.
  if (url.port && url.port !== (url.protocol === 'http:' ? '80' : '443')) {
    return null
  }

  const host = stripIpv6Brackets(url.hostname).toLowerCase()
  if (!host) return null

  const isAddress = parseIpv4(host) !== null || parseIpv6(host) !== null
  if (isAddress) return isPublicCoverImageAddress(host) ? url : null

  return isPublicHostname(host) ? url : null
}

async function lookupAddresses(hostname: string): Promise<string[]> {
  const results = await lookup(hostname, { all: true, verbatim: true })
  return results.map((result) => result.address)
}

/**
 * Resolves the host and requires every address to be public, so a public name
 * pointing at an internal address never gets contacted. Returns the address the
 * request is then pinned to.
 */
export async function resolveCoverImageAddress(
  url: URL,
  resolve: CoverRemoteImageLookup = lookupAddresses,
): Promise<string | null> {
  const host = stripIpv6Brackets(url.hostname).toLowerCase()
  if (parseIpv4(host) !== null || parseIpv6(host) !== null) {
    return isPublicCoverImageAddress(host) ? host : null
  }

  let addresses: string[]
  try {
    addresses = await resolve(host)
  } catch {
    return null
  }

  if (!addresses.length || !addresses.every(isPublicCoverImageAddress)) {
    return null
  }

  return addresses[0]
}

function isRedirect(status: number): boolean {
  return (
    status === 301 ||
    status === 302 ||
    status === 303 ||
    status === 307 ||
    status === 308
  )
}

async function readBoundedImageBody(
  response: Response,
): Promise<Buffer | null> {
  const contentType = response.headers
    .get('content-type')
    ?.split(';')[0]
    ?.trim()
    .toLowerCase()
  if (!contentType || !COVER_REMOTE_IMAGE_CONTENT_TYPES.includes(contentType)) {
    return null
  }

  const declaredLength = Number(response.headers.get('content-length'))
  if (declaredLength > COVER_REMOTE_IMAGE_MAX_BYTES) return null

  const reader = response.body?.getReader()
  if (!reader) return null

  const chunks: Uint8Array[] = []
  let size = 0
  try {
    while (true) {
      const { done, value } = await reader.read()
      if (done) break
      size += value.byteLength
      if (size > COVER_REMOTE_IMAGE_MAX_BYTES) {
        await reader.cancel()
        return null
      }
      chunks.push(value)
    }
  } catch {
    return null
  }

  const buffer = Buffer.concat(chunks)
  try {
    // A declared image type proves nothing; only a decodable payload is served
    // back, so reachable-but-unrenderable targets fail like unreachable ones.
    await sharp(buffer).metadata()
  } catch {
    return null
  }

  return buffer
}

/**
 * Requests the image over a connection pinned to the address that was just
 * validated, so the host cannot resolve to something private on the second
 * lookup `fetch()` would otherwise make. TLS keeps the original hostname for
 * SNI and certificate identity.
 *
 * Pinning needs per-request TLS control, which the Bun runtime the console
 * ships on provides. Elsewhere the request goes out by hostname.
 */
function requestCoverRemoteImage(url: URL, address: string): Promise<Response> {
  const init: RequestInit = {
    redirect: 'manual',
    signal: AbortSignal.timeout(COVER_REMOTE_IMAGE_TIMEOUT_MS),
    headers: { Accept: COVER_REMOTE_IMAGE_ACCEPT },
  }

  const host = stripIpv6Brackets(url.hostname)
  if (!('Bun' in globalThis) || host === address) {
    return fetch(url.href, init)
  }

  const pinned = new URL(url)
  pinned.hostname = address.includes(':') ? `[${address}]` : address

  return fetch(pinned.href, {
    ...init,
    headers: { ...init.headers, Host: url.host },
    tls: {
      serverName: url.hostname,
      checkServerIdentity: (_address: string, certificate: PeerCertificate) =>
        checkServerIdentity(url.hostname, certificate),
    },
  } as RequestInit)
}

/**
 * Fetches a caller-supplied image URL, or returns `null` for anything that is
 * blocked, unreachable or not an image. The uniform `null` keeps the renderer
 * from leaking whether a target exists.
 */
export async function fetchCoverRemoteImageBuffer(
  source: string | undefined,
  options: CoverRemoteImageOptions = {},
): Promise<Buffer | null> {
  const resolve = options.lookup ?? lookupAddresses
  let url = parseCoverRemoteImageUrl(source)

  for (let hop = 0; url && hop <= COVER_REMOTE_IMAGE_MAX_REDIRECTS; hop += 1) {
    const address = await resolveCoverImageAddress(url, resolve)
    if (!address) return null

    let response: Response
    try {
      response = await requestCoverRemoteImage(url, address)
    } catch {
      return null
    }

    if (!isRedirect(response.status)) {
      return response.ok ? readBoundedImageBody(response) : null
    }

    const location = response.headers.get('location')
    if (!location) return null
    try {
      url = parseCoverRemoteImageUrl(new URL(location, url).href)
    } catch {
      return null
    }
  }

  return null
}
