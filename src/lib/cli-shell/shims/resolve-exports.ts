/**
 * ESM re-export for resolve.exports (CJS-only). almostnode imports
 * `{ imports, resolve }` from "resolve.exports", which fails under Vite
 * when the almostnode bundle is resolved via createRequire.
 *
 * Use an internal alias so this file does not match the `resolve.exports` alias.
 */
import resolveExportsRoot from '@cli-shell/cjs/resolve.exports'

type ResolveExportsModule = {
  imports: (
    pkg: { name: string; imports: Record<string, unknown> },
    subpath: string,
    options?: unknown,
  ) => string[] | undefined
  resolve: (
    pkg: { name: string; exports?: Record<string, unknown> },
    subpath?: string,
    options?: unknown,
  ) => string[] | undefined
  exports: (
    pkg: { name: string; exports?: Record<string, unknown> },
    subpath: string,
    options?: unknown,
  ) => string[] | undefined
  legacy: (pkg: Record<string, unknown>, options?: unknown) => string | undefined
}

function resolveResolveExportsModule(mod: unknown): ResolveExportsModule {
  if (mod && typeof mod === 'object') {
    const record = mod as Record<string, unknown>
    if (typeof record.imports === 'function' && typeof record.resolve === 'function') {
      return record as ResolveExportsModule
    }
    const nested = record.default
    if (
      nested &&
      typeof nested === 'object' &&
      typeof (nested as ResolveExportsModule).imports === 'function' &&
      typeof (nested as ResolveExportsModule).resolve === 'function'
    ) {
      return nested as ResolveExportsModule
    }
  }
  throw new Error('Failed to load resolve.exports in the browser runtime.')
}

const { imports, resolve, exports: exportsFn, legacy } =
  resolveResolveExportsModule(resolveExportsRoot)

export { imports, resolve, exportsFn as exports, legacy }
export default { imports, resolve, exports: exportsFn, legacy }
