import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'

export function resolveChangelogAssetUrl(path?: string): string | undefined {
  if (!path) return undefined
  if (path.startsWith('http://') || path.startsWith('https://')) return path
  if (path.startsWith('/')) return `${MARKETING_SITE_ORIGIN}${path}`
  return path
}
