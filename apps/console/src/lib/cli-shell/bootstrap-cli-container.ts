import type { CliShellContainer, CliShellRuntimeConfig } from './types'
import { CLI_PROJECT_CWD } from './constants'
import {
  buildAppwriteConfigJson,
  buildCliPrefsJson,
  type ResolvedCliAuth,
} from './console-session'
import { createWasmCliContainer } from './wasm/runtime'
import { CLI_WASM_EXEC_URL, CLI_WASM_URL } from './wasm/asset-urls'
import { CLI_WASM_PREFS_DIR } from './wasm/constants'

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
  vfs.mkdirSync(CLI_WASM_PREFS_DIR, { recursive: true })

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
  vfs.mkdirSync(CLI_WASM_PREFS_DIR, { recursive: true })
  vfs.writeFileSync(
    `${CLI_WASM_PREFS_DIR}/prefs.json`,
    buildCliPrefsJson({
      consoleEndpoint: config.consoleEndpoint,
      email: config.email,
      sessionCookie: config.auth.sessionCookie,
      sessionId: `console-${config.projectId}`,
    }),
  )
}

/**
 * Bring up the CLI runtime for this console session.
 *
 * What used to be an npm install of a JavaScript CLI, an ESM-to-CJS transform
 * of its dependency tree and a patched entry point is now one wasm module. The
 * project files are written the same way, into the same paths, because the CLI
 * reads them the same way -- prefs.json and appwrite.config.json did not change
 * shape between CLI 25 and 26.
 */
export async function bootstrapCliRuntime(
  config: CliShellRuntimeConfig,
  onProgress?: BootstrapCliProgress,
): Promise<CliShellContainer> {
  onProgress?.('Preparing Appwrite CLI...')

  const container = await createWasmCliContainer({
    cwd: CLI_PROJECT_CWD,
    wasmUrl: CLI_WASM_URL,
    wasmExecUrl: CLI_WASM_EXEC_URL,
    onProgress,
  })

  writeProjectFiles(container.vfs, config)

  return container
}
