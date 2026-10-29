import type { ApiSpecPlatform, OpenApiSpec, ParsedApiSpec } from './types'
import { parseOpenApiSpec } from './parse-spec'

const specLoaders: Record<
  ApiSpecPlatform,
  () => Promise<{ default: OpenApiSpec }>
> = {
  server: () => import('@appwrite.io/specs/specs/latest/open-api3-latest.json'),
  client: () => import('@appwrite.io/specs/specs/latest/open-api3-latest.json'),
  console: () =>
    import('@appwrite.io/specs/specs/latest/open-api3-latest.json'),
}

const parsedCache = new Map<ApiSpecPlatform, ParsedApiSpec>()
const rawSpecCache = new Map<ApiSpecPlatform, OpenApiSpec>()

export async function loadRawApiSpec(
  platform: ApiSpecPlatform = 'server',
): Promise<OpenApiSpec> {
  const cached = rawSpecCache.get(platform)
  if (cached) return cached

  const loader = specLoaders[platform]
  const module = await loader()
  rawSpecCache.set(platform, module.default)
  return module.default
}

export async function loadParsedApiSpec(
  platform: ApiSpecPlatform = 'server',
): Promise<ParsedApiSpec> {
  const cached = parsedCache.get(platform)
  if (cached) return cached

  const spec = await loadRawApiSpec(platform)
  const parsed = parseOpenApiSpec(spec, platform)

  parsedCache.set(platform, parsed)
  return parsed
}

function downloadJsonFile(content: string, filename: string): void {
  const blob = new Blob([content], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

export async function downloadOpenApiSpec(
  platform: ApiSpecPlatform,
): Promise<void> {
  const spec = await loadRawApiSpec(platform)
  const content = JSON.stringify(spec, null, 2)
  downloadJsonFile(content, `appwrite-open-api3-${platform}.json`)
}

export function clearParsedApiSpecCache(): void {
  parsedCache.clear()
  rawSpecCache.clear()
}
