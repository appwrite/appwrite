import { ImageFormat, Permission, Role } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export const APPS_LOGO_BUCKET_ID = 'apps'
export const APPS_LOGO_DEFAULT_CONSOLE_REGION = 'fra'

export function resolveAppsLogoConsoleRegion(
  region?: string | null,
): string {
  if (
    typeof region === 'string' &&
    region.trim() &&
    region.trim().toLowerCase() !== 'unknown'
  ) {
    return region.trim().toLowerCase().replace(/\s+/g, '')
  }
  return APPS_LOGO_DEFAULT_CONSOLE_REGION
}

export function getAppsLogoConsoleStorageSdk(region?: string | null) {
  return sdk.forConsoleIn(resolveAppsLogoConsoleRegion(region))
}

export function buildAppLogoFilePermissions(teamId: string): string[] {
  return [
    Permission.read(Role.any()),
    Permission.update(Role.team(teamId, 'owner')),
    Permission.update(Role.team(teamId, 'developer')),
    Permission.delete(Role.team(teamId, 'owner')),
    Permission.delete(Role.team(teamId, 'developer')),
  ]
}

export function getAppLogoFilePreviewUrl(
  fileId: string,
  options?: {
    width?: number
    height?: number
    output?: ImageFormat
    region?: string | null
  },
): string {
  const consoleSdk = getAppsLogoConsoleStorageSdk(options?.region)
  return consoleSdk.storage.getFilePreview({
    bucketId: APPS_LOGO_BUCKET_ID,
    fileId,
    width: options?.width ?? 256,
    height: options?.height ?? 256,
    output: options?.output,
  })
}

const APPS_LOGO_FILE_ID_PATTERN =
  /\/storage\/buckets\/apps\/files\/([^/?#]+)\/(?:preview|view|download)/i

export function parseAppLogoFileId(logoUri: string): string | null {
  const trimmed = logoUri.trim()
  if (!trimmed) return null

  const match = trimmed.match(APPS_LOGO_FILE_ID_PATTERN)
  return match?.[1] ?? null
}

export function isAppLogoStorageUrl(logoUri: string): boolean {
  return parseAppLogoFileId(logoUri) !== null
}
