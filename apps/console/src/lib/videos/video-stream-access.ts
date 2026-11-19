import { withAdminMode } from '@/lib/appwrite/admin-resource-url'

/** Who is watching: console operators vs end users on the embed player. */
export type VideoStreamAccess = 'console' | 'end-user'

/**
 * Rewrite manifest, segment, and file URLs for the active viewer.
 * Console sessions use admin mode; public embed playback must not.
 */
export function applyVideoStreamAccess(
  url: string,
  access: VideoStreamAccess,
): string {
  if (!url) return url
  if (access === 'console') {
    if (url.includes('mode=admin')) return url
    return withAdminMode(url)
  }
  if (!url.includes('mode=admin')) return url
  try {
    const parsed = new URL(url, 'https://localhost')
    parsed.searchParams.delete('mode')
    const query = parsed.searchParams.toString()
    return `${parsed.pathname}${query ? `?${query}` : ''}${parsed.hash}`
  } catch {
    return url.replace(/([?&])mode=admin&?/g, '$1').replace(/\?$/, '')
  }
}
