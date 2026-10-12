import type {
  ApiSpecPlatform,
  AppwriteAdditionalMethod,
  AppwriteAuth,
  AppwriteOpenApiExtension,
  OpenApiOperation,
} from './types'

/** Missing or empty `platforms` means every platform. */
export function isPlatformSupported(
  xAppwrite:
    | { platforms?: string[] }
    | AppwriteAdditionalMethod
    | AppwriteOpenApiExtension
    | undefined,
  platform: ApiSpecPlatform,
): boolean {
  const platforms: readonly string[] | undefined = xAppwrite?.platforms
  if (!platforms?.length) return true
  return platforms.includes(platform)
}

/** Canonical operation IDs prefix the SDK method with the service tag. */
export function getSdkMethodName(operation: OpenApiOperation): string {
  const legacy = operation['x-appwrite']?.method
  if (legacy) return legacy
  const id = operation.operationId ?? ''
  const service = operation.tags?.[0] ?? ''
  const name = service && id.startsWith(service) ? id.slice(service.length) : id
  return name.charAt(0).toLowerCase() + name.slice(1)
}

/** Old numbered specs use a flat map; canonical specs key example auth by platform. */
export function getPlatformAuth(
  auth: AppwriteOpenApiExtension['auth'],
  platform: ApiSpecPlatform,
): AppwriteAuth | undefined {
  if (!auth) return undefined
  if (Object.values(auth).every(Array.isArray)) return auth as AppwriteAuth
  const selected = auth[platform]
  return selected && !Array.isArray(selected) ? selected : {}
}
