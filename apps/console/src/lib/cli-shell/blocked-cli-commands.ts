/**
 * Commands intercepted before they reach the CLI.
 *
 * Nearly nothing, now. The browser build of the CLI registers its own stubs for
 * everything that needs a host -- init, pull, push, run, login, update, generate,
 * types -- and explains itself better than an interception here could, because it
 * knows what it is. This list is only for the cases where the console, not the
 * CLI, owns the answer.
 */

/** Interactive / local-only subcommands named in the welcome message. */
export const WELCOME_BLOCKED_APPWRITE_SUBCOMMANDS = [
  'pull',
  'push',
  'init',
  'login',
  'run',
] as const

const BLOCKED_APPWRITE_SUBCOMMANDS: Record<string, string> = {
  logout: [
    'Logout is not supported in the browser terminal.',
    'This terminal signs in with your Console session. Sign out from the Console account menu instead.',
  ].join('\n'),
}

/**
 * Returns a user-facing message when a command should not run in the browser
 * shell. May contain multiple lines separated by \\n.
 */
export function getBlockedCliCommandMessage(rawCommand: string): string | null {
  const trimmed = rawCommand.trim()
  if (!trimmed.startsWith('appwrite')) return null

  const body = trimmed.slice('appwrite'.length).trimStart()
  if (!body) return null

  const subcommand = body.split(/\s+/)[0]?.toLowerCase()
  if (!subcommand) return null

  return BLOCKED_APPWRITE_SUBCOMMANDS[subcommand] ?? null
}
