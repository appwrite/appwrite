import { CLI_APPWRITE_BIN } from './constants'

/** Map user input to a shell command with a reliable Appwrite CLI binary path. */
export function prepareCliCommand(rawCommand: string): string {
  const trimmed = rawCommand.trim()
  if (!trimmed) return trimmed

  if (trimmed === 'appwrite') {
    return CLI_APPWRITE_BIN
  }

  if (trimmed.startsWith('appwrite ')) {
    return `${CLI_APPWRITE_BIN}${trimmed.slice('appwrite'.length)}`
  }

  return trimmed
}
