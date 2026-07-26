import type { CliShellContainer, CliShellRuntimeConfig } from './types'
import { CLI_PROJECT_CWD } from './constants'
import {
  buildAppwriteConfigJson,
  buildCliPrefsJson,
  type ResolvedCliAuth,
} from './console-session'

import {
  ensureAppwriteBinStub,
  ensureAppwriteCliExitHandling,
  installAppwriteCliPackage,
  isAppwriteCliPackageInstalled,
  resolveAppwriteCliVersion,
} from './install-appwrite-cli'
import {
  clearCliModulesCache,
  persistCliModulesCache,
  restoreCliModulesFromCache,
} from './vfs-cache'

async function installCliPackages(
  vfs: CliShellContainer['vfs'],
  onProgress?: BootstrapCliProgress,
): Promise<string> {
  let lastNpmProgressAt = 0
  const installedVersion = await installAppwriteCliPackage(vfs, (message) => {
    const formatted = formatNpmBootstrapProgress(message)
    if (!formatted) return
    const now = Date.now()
    if (now - lastNpmProgressAt < 400) return
    lastNpmProgressAt = now
    onProgress?.(formatted)
  }, '/')

  void persistCliModulesCache(vfs, installedVersion).catch(() => {})
  return installedVersion
}

/** Keep bootstrap status readable; npm emits very chatty progress lines. */
function formatNpmBootstrapProgress(message: string): string | null {
  const line = message.trim().split('\n').find(Boolean)?.trim()
  if (!line) return null

  const lower = line.toLowerCase()
  if (lower.startsWith('npm err')) return line
  if (lower.includes('added') && lower.includes('packages')) {
    return line
  }
  if (
    lower.includes('downloading') ||
    lower.includes('installing') ||
    lower.includes('fetching') ||
    lower.includes('resolving')
  ) {
    return line.length > 96 ? `${line.slice(0, 93)}...` : line
  }

  return null
}

export type BootstrapCliProgress = (message: string) => void

export type CliAuthSyncConfig = CliShellRuntimeConfig & {
  email: string
  auth: ResolvedCliAuth
}

function writeProjectFiles(
  vfs: CliShellContainer['vfs'],
  config: CliShellRuntimeConfig,
): void {
  vfs.mkdirSync(CLI_PROJECT_CWD, { recursive: true })
  vfs.mkdirSync('/home/user/.appwrite', { recursive: true })
  vfs.mkdirSync('/node_modules', { recursive: true })

  vfs.writeFileSync(
    '/package.json',
    JSON.stringify(
      {
        name: 'console-cli-runtime',
        private: true,
        version: '1.0.0',
      },
      null,
      2,
    ),
  )

  syncCliProjectConfig(vfs, config)
}

/** Keep appwrite.config.json aligned with the active console project. */
export function syncCliProjectConfig(
  vfs: CliShellContainer['vfs'],
  config: Pick<
    CliShellRuntimeConfig,
    'projectId' | 'projectEndpoint' | 'organizationId'
  >,
): void {
  vfs.mkdirSync(CLI_PROJECT_CWD, { recursive: true })
  vfs.writeFileSync(
    `${CLI_PROJECT_CWD}/appwrite.config.json`,
    buildAppwriteConfigJson({
      projectId: config.projectId,
      endpoint: config.projectEndpoint,
      organizationId: config.organizationId,
    }),
  )
}

export function syncCliAuthFiles(
  vfs: CliShellContainer['vfs'],
  config: CliAuthSyncConfig,
): void {
  vfs.mkdirSync('/home/user/.appwrite', { recursive: true })
  vfs.writeFileSync(
    '/home/user/.appwrite/prefs.json',
    buildCliPrefsJson({
      consoleEndpoint: config.consoleEndpoint,
      email: config.email,
      sessionCookie: config.auth.sessionCookie,
      sessionId: `console-${config.projectId}`,
    }),
  )
}

export async function bootstrapCliRuntime(
  config: CliShellRuntimeConfig,
  onProgress?: BootstrapCliProgress,
): Promise<CliShellContainer> {
  const { createContainer } = await import('almostnode')

  const container = createContainer({
    cwd: CLI_PROJECT_CWD,
    env: {
      HOME: '/home/user',
      NODE_ENV: 'development',
      TERM: 'xterm-256color',
      PATH: '/usr/local/bin:/usr/bin:/bin:/node_modules/.bin',
    },
  })

  const { vfs } = container
  writeProjectFiles(vfs, config)

  onProgress?.('Preparing Appwrite CLI...')
  const latestCliVersion = await resolveAppwriteCliVersion()
  const restoredFromCache = await restoreCliModulesFromCache(
    vfs,
    latestCliVersion,
  )

  let cliReady =
    restoredFromCache &&
    isAppwriteCliPackageInstalled(vfs) &&
    ensureAppwriteBinStub(vfs)

  if (cliReady) {
    onProgress?.(`Loaded cached Appwrite CLI ${latestCliVersion}.`)
  } else {
    if (restoredFromCache) {
      onProgress?.(`Updating Appwrite CLI to ${latestCliVersion}...`)
      await clearCliModulesCache(latestCliVersion)
    } else {
      onProgress?.(
        `Downloading Appwrite CLI ${latestCliVersion} (first run may take a minute)...`,
      )
    }

    const installedVersion = await installCliPackages(vfs, onProgress)
    cliReady = isAppwriteCliPackageInstalled(vfs) && ensureAppwriteBinStub(vfs)
    if (cliReady && installedVersion !== latestCliVersion) {
      onProgress?.(`Installed Appwrite CLI ${installedVersion}.`)
    }
  }

  if (!cliReady) {
    throw new Error(
      'Appwrite CLI failed to install in the browser runtime. Check your network connection and try again.',
    )
  }

  // Apply after install/cache restore so every session gets the exit catch,
  // including CLIs restored from IndexedDB that predate this patch.
  ensureAppwriteCliExitHandling(vfs)

  return container
}


/** @deprecated Use bootstrapCliRuntime + syncCliAuthFiles instead. */
export async function bootstrapCliContainer(
  config: CliAuthSyncConfig,
  onProgress?: BootstrapCliProgress,
): Promise<CliShellContainer> {
  const container = await bootstrapCliRuntime(config, onProgress)
  syncCliAuthFiles(container.vfs, config)
  return container
}
