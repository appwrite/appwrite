import { sdk } from '@/lib/appwrite/sdk'

/**
 * Loads an Appwrite image URL (e.g. avatars/getQR) with the same auth headers as SDK
 * requests. Plain <img src> cannot send X-Fallback-Cookies or session headers.
 */
export async function fetchAuthenticatedImageBlobUrl(
  url: string,
  signal?: AbortSignal,
): Promise<string> {
  const headers: Record<string, string> = {
    ...sdk.forConsole.client.headers,
  }

  if (typeof window !== 'undefined') {
    try {
      const cookieFallback = window.localStorage.getItem('cookieFallback')
      if (cookieFallback) {
        headers['X-Fallback-Cookies'] = cookieFallback
      }
    } catch {
      // localStorage unavailable
    }
  }

  const response = await fetch(url, {
    method: 'GET',
    credentials: 'include',
    headers,
    cache: 'no-store',
    signal,
  })

  if (!response.ok) {
    throw new Error(`Failed to load image (${response.status})`)
  }

  const blob = await response.blob()
  if (!blob.size) {
    throw new Error('Empty image response')
  }

  return URL.createObjectURL(blob)
}

export function revokeAuthenticatedImageBlobUrl(url: string | null | undefined) {
  if (url?.startsWith('blob:')) {
    URL.revokeObjectURL(url)
  }
}
