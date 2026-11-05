import { isReferenceVersion } from './constants'

/**
 * API reference pages for semver versions duplicate cloud content.
 * Non-cloud URLs use `rel=canonical` to the matching cloud path and
 * `noindex, follow` (see `getDocsRouteHead`) so crawlers consolidate
 * signals without relying on robots.txt disallow rules.
 */
export function getApiReferenceCanonicalSlug(slug: string): string {
  const normalized = slug.replace(/^\/+|\/+$/g, '')
  const match = normalized.match(/^references\/([^/]+)\/(.+)$/)
  if (!match) return normalized

  const [, version, rest] = match
  if (version === 'cloud' || !isReferenceVersion(version)) {
    return normalized
  }

  return `references/cloud/${rest}`
}
