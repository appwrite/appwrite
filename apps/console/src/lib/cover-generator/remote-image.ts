/** Hosts cover images may be fetched from; subdomains of these are allowed too. */
export const COVER_REMOTE_IMAGE_HOSTS = [
  'appwrite.io',
  'raw.githubusercontent.com',
  'avatars.githubusercontent.com',
] as const

const MAX_BYTES = 10 * 1024 * 1024
const TIMEOUT_MS = 10_000

/** An `https:` URL on one of {@link COVER_REMOTE_IMAGE_HOSTS}, without credentials or a custom port. */
export function isAllowedCoverRemoteImageUrl(value: string): boolean {
  let url: URL
  try {
    url = new URL(value)
  } catch {
    return false
  }

  if (url.protocol !== 'https:') return false
  if (url.username || url.password || url.port) return false

  const host = url.hostname
  return COVER_REMOTE_IMAGE_HOSTS.some(
    (allowed) => host === allowed || host.endsWith(`.${allowed}`),
  )
}

/**
 * Fetch a remote cover image. Redirects are not followed, so the response
 * always comes from the allowed host, and the body is capped in size and time.
 */
export async function fetchCoverRemoteImage(
  value: string,
): Promise<Buffer | null> {
  if (!isAllowedCoverRemoteImageUrl(value)) return null

  try {
    const response = await fetch(value, {
      redirect: 'manual',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    })
    if (response.status !== 200 || !response.body) return null

    const declared = Number(response.headers.get('content-length') ?? 0)
    if (declared > MAX_BYTES) {
      await response.body.cancel()
      return null
    }

    const chunks: Uint8Array[] = []
    let size = 0
    for await (const chunk of response.body) {
      size += chunk.byteLength
      if (size > MAX_BYTES) return null
      chunks.push(chunk)
    }
    return Buffer.concat(chunks)
  } catch {
    return null
  }
}
