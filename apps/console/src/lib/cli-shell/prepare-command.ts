/** Outcome of turning typed input into something the CLI runtime can run. */
export type PreparedCliCommand =
  | { type: 'run'; args: string }
  | { type: 'unsupported'; message: string }
  | { type: 'empty' }

/**
 * Map user input to CLI arguments.
 *
 * The `appwrite` prefix is stripped rather than resolved to a path: there is no
 * shell and no bin directory any more, only a wasm module that takes argv. And
 * because there is no shell, a command that is not `appwrite` cannot run at all
 * -- the old runtime emulated Node well enough for `ls` and `cat` to work, and
 * this one deliberately does not pretend to.
 */
export function prepareCliCommand(rawCommand: string): PreparedCliCommand {
  const trimmed = rawCommand.trim()
  if (!trimmed) return { type: 'empty' }

  if (trimmed === 'appwrite') {
    return { type: 'run', args: '' }
  }

  if (trimmed.startsWith('appwrite ')) {
    return { type: 'run', args: trimmed.slice('appwrite'.length).trim() }
  }

  const name = trimmed.split(/\s+/)[0] ?? trimmed
  return {
    type: 'unsupported',
    message: [
      `${name}: not available in this terminal.`,
      'This is the Appwrite CLI, not a shell. Every command starts with `appwrite` — try `appwrite help`.',
    ].join('\n'),
  }
}
