import type { ApiSpecPlatform, OpenApiSpec, ParsedApiSpec } from './types'
import { parseOpenApiSpec } from './parse-spec'

const specLoaders: Record<
  ApiSpecPlatform,
  () => Promise<{ default: OpenApiSpec }>
> = {
  server: () => import('@appwrite.io/specs/specs/latest/open-api3-latest-server.json'),
  client: () => import('@appwrite.io/specs/specs/latest/open-api3-latest-client.json'),
  console: () => import('@appwrite.io/specs/specs/latest/open-api3-latest-console.json'),
}

const parsedCache = new Map<ApiSpecPlatform, ParsedApiSpec>()

export async function loadParsedApiSpec(
  platform: ApiSpecPlatform = 'server',
): Promise<ParsedApiSpec> {
  const cached = parsedCache.get(platform)
  if (cached) return cached

  const loader = specLoaders[platform]
  const module = await loader()
  const parsed = parseOpenApiSpec(module.default, platform)
  parsedCache.set(platform, parsed)
  return parsed
}

export function clearParsedApiSpecCache(): void {
  parsedCache.clear()
}
