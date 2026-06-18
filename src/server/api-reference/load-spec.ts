import type { OpenApiSpec } from '@/lib/api-explorer/types'
import {
  getSpecFilename,
  getSpecMode,
  resolveSpecVersionDirs,
  type ReferencePlatform,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import { ReferenceNotFoundError } from '@/lib/docs/references/errors'

const specModules = import.meta.glob<{ default: OpenApiSpec }>(
  '../../../node_modules/@appwrite.io/specs/specs/**/*.json',
  { import: 'default' },
)

const specCache = new Map<string, OpenApiSpec>()

function resolveSpecModuleKey(
  specDir: string,
  mode: 'client' | 'server' | 'console',
): string {
  const filename = getSpecFilename(specDir, mode)
  return `../../../node_modules/@appwrite.io/specs/specs/${specDir}/${filename}`
}

async function loadSpecByKey(key: string): Promise<OpenApiSpec> {
  const cached = specCache.get(key)
  if (cached) return cached

  const loader = specModules[key]
  if (!loader) {
    throw new ReferenceNotFoundError(`Missing OpenAPI spec ${key}`)
  }

  const spec = await loader()
  specCache.set(key, spec)
  return spec
}

export async function loadReferenceOpenApiSpec(
  version: ReferenceVersion,
  platform: ReferencePlatform,
): Promise<OpenApiSpec> {
  const mode = getSpecMode(platform)
  const { specDir } = resolveSpecVersionDirs(version)
  return loadSpecByKey(resolveSpecModuleKey(specDir, mode))
}

export async function loadReferenceOpenApiSpecByMode(
  version: ReferenceVersion,
  mode: 'client' | 'server' | 'console',
): Promise<OpenApiSpec> {
  const { specDir } = resolveSpecVersionDirs(version)
  return loadSpecByKey(resolveSpecModuleKey(specDir, mode))
}

export async function loadReferenceConsoleSpec(
  version: ReferenceVersion,
): Promise<OpenApiSpec> {
  const { specDir } = resolveSpecVersionDirs(version)
  return loadSpecByKey(resolveSpecModuleKey(specDir, 'console'))
}
