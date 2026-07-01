import {
  CLI_APPWRITE_CLI_DIST_TAG,
  CLI_APPWRITE_CLI_PACKAGE,
} from './constants'
import { resolveAppwriteCliVersion } from './install-appwrite-cli'
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
    version: CLI_APPWRITE_CLI_DIST_TAG,
    packageName: CLI_APPWRITE_CLI_PACKAGE,
    indexedDb: 'console-cli-shell',
    cache: null,
  }
}

export async function loadCliTerminalCacheSummary(): Promise<CliTerminalCacheSummary> {
  const version = await resolveAppwriteCliVersion().catch(
    () => CLI_APPWRITE_CLI_DIST_TAG,
  )
  const cache = await readCliModulesCacheMeta(version)
  return {
    ...getCliTerminalCacheSummary(),
    version,
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
