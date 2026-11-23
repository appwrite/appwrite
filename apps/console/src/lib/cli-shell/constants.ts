import { WELCOME_BLOCKED_APPWRITE_SUBCOMMANDS } from './blocked-cli-commands'
import {
  CLI_TERMINAL_CYAN,
  CLI_TERMINAL_GREEN,
  CLI_TERMINAL_MUTED,
  CLI_TERMINAL_RESET,
  CLI_TERMINAL_YELLOW,
  formatTerminalLink,
} from './cli-terminal-api'
import type { CliShellLine } from './types'

export const CLI_DOCS_URL = '/docs/tooling/command-line/commands'

/** Working directory for Appwrite CLI project context inside the CLI VFS. */
export const CLI_PROJECT_CWD = '/project'

/** Sentinel cookie written to CLI prefs when auth uses browser cookie forwarding. */
export const BROWSER_PROXY_SESSION_COOKIE =
  'a_session_console=__browser_session__'

/** Matches {@link ConsoleFooter} compact bar height (`min-h-[54px]`). */
export const CLI_SHELL_COLLAPSED_HEIGHT_PX = 54

/** Panel expand/collapse animation duration (ms). */
export const CLI_SHELL_COLLAPSE_MS = 200

export const CLI_SHELL_MIN_HEIGHT_PX = 160
export const CLI_SHELL_MAX_HEIGHT_RATIO = 0.55
export const CLI_SHELL_DEFAULT_HEIGHT_PX = 280

export const CLI_BOOTSTRAP_READY_MESSAGE = 'Appwrite CLI is ready.'

export const CLI_SHELL_TRY_COMMANDS = [
  'appwrite help',
  'appwrite whoami',
  'appwrite users list --json',
  'appwrite functions list',
  'appwrite tablesdb list',
] as const

export function createCliShellWelcomeLines(): CliShellLine[] {
  const blockedList = WELCOME_BLOCKED_APPWRITE_SUBCOMMANDS.map(
    (name) => `${CLI_TERMINAL_YELLOW}${name}${CLI_TERMINAL_MUTED}`,
  ).join(', ')

  return [
    {
      type: 'rich',
      text: [
        CLI_TERMINAL_CYAN,
        'Appwrite CLI',
        CLI_TERMINAL_RESET,
        CLI_TERMINAL_MUTED,
        ' · run API commands against this project from your browser.',
        CLI_TERMINAL_RESET,
      ].join(''),
    },
    {
      type: 'rich',
      text: [
        CLI_TERMINAL_GREEN,
        '✓',
        CLI_TERMINAL_RESET,
        ' ',
        CLI_TERMINAL_MUTED,
        'Session and project context are configured automatically.',
        CLI_TERMINAL_RESET,
      ].join(''),
    },
    {
      type: 'rich',
      text: [
        CLI_TERMINAL_YELLOW,
        '!',
        CLI_TERMINAL_RESET,
        ' ',
        CLI_TERMINAL_MUTED,
        'Interactive commands (',
        blockedList,
        ') are not supported here.',
        CLI_TERMINAL_RESET,
      ].join(''),
    },
    {
      type: 'rich',
      text: [
        CLI_TERMINAL_MUTED,
        'Docs: ',
        formatTerminalLink(CLI_DOCS_URL),
      ].join(''),
    },
    {
      type: 'suggestions' as const,
      commands: CLI_SHELL_TRY_COMMANDS,
    },
  ]
}
