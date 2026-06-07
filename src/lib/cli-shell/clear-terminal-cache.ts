import {
  CLI_APPWRITE_CLI_PACKAGE,
  CLI_APPWRITE_CLI_VERSION,
} from './constants'
import { resetAllCliShellBootstraps } from './bootstrap-state'
import {
  clearAllCliModulesCaches,
  readCliModulesCacheMeta,
  type CliModulesCacheMeta,
} from './vfs-cache'

export const CLI_TERMINAL_CACHE_CLEARED = 'cli-terminal-cache-cleared'

export type CliTerminalCacheSummary = {
  version: string
  packageName: string
  indexedDb: string
  cache: CliModulesCacheMeta | null
}

export function getCliTerminalCacheSummary(): CliTerminalCacheSummary {
  return {
    version: CLI_APPWRITE_CLI_VERSION,
    packageName: CLI_APPWRITE_CLI_PACKAGE,
    indexedDb: 'console-cli-shell',
    cache: null,
  }
}

export async function loadCliTerminalCacheSummary(): Promise<CliTerminalCacheSummary> {
  const cache = await readCliModulesCacheMeta(CLI_APPWRITE_CLI_VERSION)
  return {
    ...getCliTerminalCacheSummary(),
    cache,
  }
}

/** Clears cached Appwrite CLI install (IndexedDB) and in-memory shell bootstraps. */
export async function clearCliTerminalCache(): Promise<void> {
  await clearAllCliModulesCaches()
  resetAllCliShellBootstraps()
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(CLI_TERMINAL_CACHE_CLEARED))
  }
}
