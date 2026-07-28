import type { ApiSpecPlatform, OpenApiSpec, ParsedApiSpec } from './types'
import { mergeConsoleOnlyDatabaseServices, parseOpenApiSpec } from './parse-spec'
import { LATEST_EXAMPLES_VERSION } from '@/lib/docs/references/reference-versions'

/**
 * Numbered console OpenAPI used for native DB engines (postgresql / mysql / mongo).
 * The floating `latest` console folder may still ship the legacy `/compute` surface.
 */
const NUMBERED_CONSOLE_SPEC_LOADERS: Record<
  string,
  () => Promise<{ default: OpenApiSpec }>
> = {
  '1.9.x': () =>
    import('@appwrite.io/specs/specs/1.9.x/open-api3-1.9.x-console.json'),
  '1.8.x': () =>
    import('@appwrite.io/specs/specs/1.8.x/open-api3-1.8.x-console.json'),
}

const specLoaders: Record<
  ApiSpecPlatform,
  () => Promise<{ default: OpenApiSpec }>
> = {
  server: () => import('@appwrite.io/specs/specs/latest/open-api3-latest-server.json'),
  client: () => import('@appwrite.io/specs/specs/latest/open-api3-latest-client.json'),
  console: () => import('@appwrite.io/specs/specs/latest/open-api3-latest-console.json'),
}

const parsedCache = new Map<ApiSpecPlatform, ParsedApiSpec>()
const rawSpecCache = new Map<ApiSpecPlatform, OpenApiSpec>()
let numberedConsoleSpecCache: OpenApiSpec | undefined

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

async function loadNumberedConsoleSpec(): Promise<OpenApiSpec> {
  if (numberedConsoleSpecCache) return numberedConsoleSpecCache

  const preferred = NUMBERED_CONSOLE_SPEC_LOADERS[LATEST_EXAMPLES_VERSION]
  if (preferred) {
    const module = await preferred()
    numberedConsoleSpecCache = module.default
    return numberedConsoleSpecCache
  }

  // Fall back through known numbered loaders, then floating latest.
  for (const loader of Object.values(NUMBERED_CONSOLE_SPEC_LOADERS)) {
    try {
      const module = await loader()
      numberedConsoleSpecCache = module.default
      return numberedConsoleSpecCache
    } catch {
      // try next
    }
  }

  numberedConsoleSpecCache = await loadRawApiSpec('console')
  return numberedConsoleSpecCache
}

function consoleSpecHasNativeDatabaseServices(spec: OpenApiSpec): boolean {
  for (const pathItem of Object.values(spec.paths ?? {})) {
    for (const [method, operation] of Object.entries(pathItem ?? {})) {
      if (method.startsWith('x-') || !operation || typeof operation !== 'object') {
        continue
      }
      const tags = (operation as { tags?: string[] }).tags ?? []
      if (
        tags.includes('postgresql') ||
        tags.includes('mysql') ||
        tags.includes('mongo')
      ) {
        return true
      }
    }
  }
  return false
}

async function loadConsoleSpecForNativeDatabases(): Promise<OpenApiSpec> {
  const latestConsole = await loadRawApiSpec('console')
  if (consoleSpecHasNativeDatabaseServices(latestConsole)) {
    return latestConsole
  }
  return loadNumberedConsoleSpec()
}

export async function loadParsedApiSpec(
  platform: ApiSpecPlatform = 'server',
): Promise<ParsedApiSpec> {
  const cached = parsedCache.get(platform)
  if (cached) return cached

  const spec = await loadRawApiSpec(platform)
  let parsed = parseOpenApiSpec(spec, platform)

  // Project explorer uses server/client specs; native engines live on console.
  if (platform === 'server' || platform === 'client') {
    const consoleSpec = await loadConsoleSpecForNativeDatabases()
    const consoleParsed = parseOpenApiSpec(consoleSpec, 'console')
    parsed = mergeConsoleOnlyDatabaseServices(parsed, consoleParsed)
  }

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
  numberedConsoleSpecCache = undefined
}
