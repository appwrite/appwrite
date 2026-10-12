import { accessSync, constants } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const SPECS_DIR_NAME = 'specs'

let specsRoot: string | undefined

function hasSpecsData(root: string): boolean {
  try {
    accessSync(join(root, 'specs'), constants.R_OK)
    return true
  } catch {
    return false
  }
}

function resolveSpecsPackageRoot(): string {
  const moduleDir = dirname(fileURLToPath(import.meta.url))
  const cwd = process.cwd()

  const candidates = [
    process.env.APPWRITE_SPECS_ROOT,
    // Docker / local dev: full repo with node_modules at cwd.
    join(cwd, 'node_modules', '@appwrite.io', 'specs'),
    // Bundled SSR chunk at dist/server/assets/* -> dist/specs.
    join(moduleDir, '..', '..', SPECS_DIR_NAME),
    // Sites-style layout: cwd is dist/ (server at dist/server/assets).
    join(cwd, SPECS_DIR_NAME),
    join(cwd, 'dist', SPECS_DIR_NAME),
    // Back-compat for older deploys.
    join(moduleDir, '..', '..', 'appwrite-specs'),
    join(cwd, 'appwrite-specs'),
    join(cwd, 'dist', 'appwrite-specs'),
  ].filter((value): value is string => Boolean(value))

  for (const candidate of candidates) {
    if (hasSpecsData(candidate)) return candidate
  }

  throw new Error(
    'Could not locate @appwrite.io/specs data. Expected specs/ under node_modules or dist/specs.',
  )
}

/** Absolute path to the root containing `specs/` and `examples/` from @appwrite.io/specs. */
export function getSpecsPackageRoot(): string {
  if (!specsRoot) {
    specsRoot = resolveSpecsPackageRoot()
  }
  return specsRoot
}
