import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'

const LOCAL_ASSET_PREFIXES = ['/images/blog-local/', '/images/changelog-local/']

export function resolveChangelogAssetUrl(path?: string): string | undefined {
  if (!path) return undefined
  if (path.startsWith('http://') || path.startsWith('https://')) return path
  if (LOCAL_ASSET_PREFIXES.some((prefix) => path.startsWith(prefix))) return path
  if (path.startsWith('/')) return `${MARKETING_SITE_ORIGIN}${path}`
  return path
}
