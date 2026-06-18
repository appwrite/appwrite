import { isReferenceVersion } from './constants'

/**
 * API reference pages for semver versions duplicate cloud content.
 * Non-cloud slugs canonicalize to the matching cloud path for SEO.
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
