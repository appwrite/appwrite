import { createRequire } from 'node:module'
import { dirname } from 'node:path'

const require = createRequire(import.meta.url)

let specsRoot: string | undefined

/** Absolute path to the installed `@appwrite.io/specs` package root. */
export function getSpecsPackageRoot(): string {
  if (!specsRoot) {
    specsRoot = dirname(require.resolve('@appwrite.io/specs/package.json'))
  }
  return specsRoot
}
