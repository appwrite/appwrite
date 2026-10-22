import {
  resolvesToPublicAddresses,
  type AddressLookup,
} from '@/server/lib/public-address'

/** Largest image the cover and diagram renderers download. */
export const COVER_REMOTE_IMAGE_MAX_BYTES = 20 * 1024 * 1024

const COVER_REMOTE_IMAGE_TIMEOUT_MS = 10_000
const COVER_REMOTE_IMAGE_MAX_REDIRECTS = 3

/**
 * Download an image a cover or diagram references by URL (`screenshot`,
 * `icon`, logos, or a bundled asset the server does not have on disk).
 * Only http(s) URLs without credentials on hosts that resolve to public
 * addresses are fetched; each redirect is checked the same way, and slow or
 * oversized responses are dropped. Returns null when nothing usable came back.
 */
export async function fetchCoverRemoteImage(
  url: string,
  resolve?: AddressLookup,
): Promise<Buffer | null> {
  let target: URL
  try {
    target = new URL(url)
  } catch {
    return null
  }

  try {
    for (let hop = 0; hop <= COVER_REMOTE_IMAGE_MAX_REDIRECTS; hop++) {
      if (!(await isFetchableUrl(target, resolve))) return null

      const response = await fetch(target, {
        redirect: 'manual',
        signal: AbortSignal.timeout(COVER_REMOTE_IMAGE_TIMEOUT_MS),
      })
      const location = response.headers.get('location')
      if (response.status >= 300 && response.status < 400 && location) {
        await response.body?.cancel()
        target = new URL(location, target)
        continue
      }
      if (!response.ok || !response.body) return null
      return await readCappedBody(response.body)
    }
  } catch {
    return null
  }
  return null
}

async function isFetchableUrl(
  url: URL,
  resolve?: AddressLookup,
): Promise<boolean> {
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return false
  if (url.username || url.password) return false
  return resolvesToPublicAddresses(url.hostname, resolve)
}

async function readCappedBody(
  body: ReadableStream<Uint8Array>,
): Promise<Buffer | null> {
  const chunks: Uint8Array[] = []
  let size = 0
  const reader = body.getReader()
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    size += value.byteLength
    if (size > COVER_REMOTE_IMAGE_MAX_BYTES) {
      await reader.cancel()
      return null
    }
    chunks.push(value)
  }
  return Buffer.concat(chunks)
}
