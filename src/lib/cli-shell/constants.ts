import type { CliShellLine } from './types'

/** Working directory for Appwrite CLI project context inside almostnode VFS. */
export const CLI_PROJECT_CWD = '/project'

/** Resolved path to the Appwrite CLI binary in the almostnode VFS. */
export const CLI_APPWRITE_BIN = '/node_modules/.bin/appwrite'

/** Pinned Appwrite CLI version installed in the browser runtime. */
export const CLI_APPWRITE_CLI_VERSION = '22.0.0'

/** npm package name for the Appwrite CLI. */
export const CLI_APPWRITE_CLI_PACKAGE = 'appwrite-cli'

/** VFS paths restored from IndexedDB to skip reinstalling the CLI. */
export const CLI_VFS_CACHE_ROOT = '/node_modules'

/** Sentinel cookie written to CLI prefs when auth uses browser cookie forwarding. */
export const BROWSER_PROXY_SESSION_COOKIE =
  'a_session_console=__browser_session__'

/** Matches {@link ConsoleFooter} compact bar height (`min-h-[54px]`). */
export const CLI_SHELL_COLLAPSED_HEIGHT_PX = 54

export const CLI_SHELL_MIN_HEIGHT_PX = 160
export const CLI_SHELL_MAX_HEIGHT_RATIO = 0.55
export const CLI_SHELL_DEFAULT_HEIGHT_PX = 280

export const CLI_SHELL_WELCOME_TEXT = [
  'Run Appwrite CLI commands against this project from your browser.',
  'Your console session and project context are configured automatically.',
] as const

export const CLI_SHELL_TRY_COMMANDS = [
  'appwrite whoami',
  'appwrite users list --json',
  'appwrite functions list',
] as const

export function createCliShellWelcomeLines(): CliShellLine[] {
  return [
    ...CLI_SHELL_WELCOME_TEXT.map((text) => ({ type: 'system' as const, text })),
    {
      type: 'suggestions' as const,
      commands: CLI_SHELL_TRY_COMMANDS,
    },
  ]
}
