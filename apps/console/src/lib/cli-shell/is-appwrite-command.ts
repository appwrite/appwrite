/** True when the user input targets the Appwrite CLI binary. */
export function isAppwriteCliCommand(rawCommand: string): boolean {
  const trimmed = rawCommand.trim()
  return trimmed === 'appwrite' || trimmed.startsWith('appwrite ')
}
