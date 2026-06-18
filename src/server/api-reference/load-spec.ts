import { readFile } from 'node:fs/promises'
import { join } from 'node:path'
import type { OpenApiSpec } from '@/lib/api-explorer/types'
import {
  getSpecFilename,
  getSpecMode,
  resolveSpecVersionDirs,
  type ReferencePlatform,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import { ReferenceNotFoundError } from '@/lib/docs/references/errors'
import { getSpecsPackageRoot } from './specs-path'

const specCache = new Map<string, OpenApiSpec>()

function resolveSpecFilePath(
  specDir: string,
  mode: 'client' | 'server' | 'console',
): string {
  const filename = getSpecFilename(specDir, mode)
  return join(getSpecsPackageRoot(), 'specs', specDir, filename)
}

async function loadSpecByPath(
  specDir: string,
  mode: 'client' | 'server' | 'console',
): Promise<OpenApiSpec> {
  const cacheKey = `${specDir}/${mode}`
  const cached = specCache.get(cacheKey)
  if (cached) return cached

  const filePath = resolveSpecFilePath(specDir, mode)
  let raw: string
  try {
    raw = await readFile(filePath, 'utf-8')
  } catch {
    throw new ReferenceNotFoundError(`Missing OpenAPI spec ${filePath}`)
  }

  const spec = JSON.parse(raw) as OpenApiSpec
  specCache.set(cacheKey, spec)
  return spec
}

export async function loadReferenceOpenApiSpec(
  version: ReferenceVersion,
  platform: ReferencePlatform,
): Promise<OpenApiSpec> {
  const mode = getSpecMode(platform)
  const { specDir } = resolveSpecVersionDirs(version)
  return loadSpecByPath(specDir, mode)
}

export async function loadReferenceOpenApiSpecByMode(
  version: ReferenceVersion,
  mode: 'client' | 'server' | 'console',
): Promise<OpenApiSpec> {
  const { specDir } = resolveSpecVersionDirs(version)
  return loadSpecByPath(specDir, mode)
}

export async function loadReferenceConsoleSpec(
  version: ReferenceVersion,
): Promise<OpenApiSpec> {
  const { specDir } = resolveSpecVersionDirs(version)
  return loadSpecByPath(specDir, 'console')
}
