import {
  getProjectApiEndpoint,
  getProjectRegion,
} from '@/lib/appwrite/sdk'

/** S3 API path segment on the project API host. */
export const S3_STORAGE_PATH = '/s3'

/** Storage scopes required for S3-compatible bucket and object access. */
export const S3_STORAGE_API_KEY_SCOPES = [
  'buckets.read',
  'buckets.write',
  'files.read',
  'files.write',
] as const

/** Default name when creating an API key from the S3 Connect tab. */
export const S3_STORAGE_API_KEY_DEFAULT_NAME = 'S3 Storage access'

export function normalizeProjectS3Region(
  projectRegion?: string | null,
): string | undefined {
  if (
    typeof projectRegion !== 'string' ||
    !projectRegion.trim() ||
    projectRegion.trim().toLowerCase() === 'unknown'
  ) {
    return undefined
  }

  return projectRegion.trim().toLowerCase().replace(/\s+/g, '')
}

/**
 * S3 region advertised to clients. Matches the Appwrite project region (e.g. fra, nyc).
 */
export function getProjectS3StorageRegion(
  projectId: string,
  projectRegion?: string | null,
): string | undefined {
  return (
    normalizeProjectS3Region(projectRegion) ??
    normalizeProjectS3Region(getProjectRegion(projectId))
  )
}

/**
 * Project-scoped HTTPS endpoint for S3-compatible Storage access.
 * Access key ID = project ID; secret = API key with Storage scope (SigV4).
 */
export function getProjectS3StorageEndpoint(projectId: string): string {
  const base = getProjectApiEndpoint(projectId).replace(/\/$/, '')
  return `${base}${S3_STORAGE_PATH}`
}
