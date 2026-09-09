import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'

/** Image trees shipped in this repo's public/ directory (served locally, not from the marketing site). */
const LOCAL_ASSET_PREFIXES = ['/images/blog/']

export function resolveChangelogAssetUrl(path?: string): string | undefined {
  if (!path) return undefined
  if (path.startsWith('http://') || path.startsWith('https://')) return path
  if (LOCAL_ASSET_PREFIXES.some((prefix) => path.startsWith(prefix))) return path
  if (path.startsWith('/')) return `${MARKETING_SITE_ORIGIN}${path}`
  return path
}
