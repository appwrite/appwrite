import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { isDatabaseTypeFeatureEnabled } from '@/lib/database-routes'

/** Docs URL segments for database types that follow console feature flags. */
const FEATURE_GATED_DATABASE_DOCS_SEGMENTS = [
  'documentsdb',
  'vectorsdb',
  'postgresql',
  'mysql',
] as const

type FeatureGatedDatabaseDocsSegment =
  (typeof FEATURE_GATED_DATABASE_DOCS_SEGMENTS)[number]

function segmentFromSlug(slug: string): FeatureGatedDatabaseDocsSegment | null {
  for (const segment of FEATURE_GATED_DATABASE_DOCS_SEGMENTS) {
    const prefix = `products/databases/${segment}`
    if (slug === prefix || slug.startsWith(`${prefix}/`)) {
      return segment
    }
  }
  return null
}

function segmentFromDocsPath(path: string): FeatureGatedDatabaseDocsSegment | null {
  const normalized = path.split(/[?#]/, 2)[0] ?? path
  for (const segment of FEATURE_GATED_DATABASE_DOCS_SEGMENTS) {
    const prefix = `/docs/products/databases/${segment}`
    if (normalized === prefix || normalized.startsWith(`${prefix}/`)) {
      return segment
    }
  }
  return null
}

export function isDatabaseTypeDocsSlug(slug: string): boolean {
  return segmentFromSlug(slug) !== null
}

export function isDatabaseTypeDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return segmentFromDocsPath(normalized) !== null
}

export function isDatabaseTypeDocsHref(href: string): boolean {
  return segmentFromDocsPath(href) !== null
}

/** True when this docs slug is a gated database type and its feature flag is off. */
export function isDatabaseTypeDocsSlugHidden(slug: string): boolean {
  const segment = segmentFromSlug(slug)
  if (!segment) return false
  return !isDatabaseTypeFeatureEnabled(segment, getActiveProfileFeatures())
}

/** True when this docs href points at a gated database type whose feature flag is off. */
export function isDatabaseTypeDocsHrefHidden(href: string): boolean {
  const segment = segmentFromDocsPath(href)
  if (!segment) return false
  return !isDatabaseTypeFeatureEnabled(segment, getActiveProfileFeatures())
}

/** True when this docs pathname is a gated database type whose feature flag is off. */
export function isDatabaseTypeDocsPathnameHidden(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  const segment = segmentFromDocsPath(normalized)
  if (!segment) return false
  return !isDatabaseTypeFeatureEnabled(segment, getActiveProfileFeatures())
}
