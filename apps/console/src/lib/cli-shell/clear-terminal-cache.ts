import { resetAllCliShellBootstraps } from './bootstrap-state'
import { clearCachedModules } from './wasm/module-cache'
import { CLI_WASM_VERSION } from './wasm/constants'

export const CLI_TERMINAL_CACHE_CLEARED = 'cli-terminal-cache-cleared'

export type CliTerminalCacheSummary = {
  version: string
  packageName: string
  indexedDb: string
}

export function getCliTerminalCacheSummary(): CliTerminalCacheSummary {
  return {
    version: CLI_WASM_VERSION,
    packageName: 'appwrite-cli-wasm',
    indexedDb: 'console-cli-wasm',
  }
}

export async function loadCliTerminalCacheSummary(): Promise<CliTerminalCacheSummary> {
  // The version is pinned in source rather than resolved from a registry, so
  // there is nothing to await any more. Kept async because callers are.
  return getCliTerminalCacheSummary()
}

/** Clears the cached CLI module (IndexedDB) and in-memory shell bootstraps. */
export async function clearCliTerminalCache(): Promise<void> {
  await clearCachedModules()
  resetAllCliShellBootstraps()
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(CLI_TERMINAL_CACHE_CLEARED))
  }
}
