/** Top-level Appwrite CLI commands that require prompts or a local environment. */
const BLOCKED_APPWRITE_SUBCOMMANDS: Record<string, string> = {
  login: [
    'Sign-in is not available in the browser terminal.',
    'You are already signed in with your Console session. Run appwrite whoami to verify.',
  ].join('\n'),
  logout: [
    'Logout is not supported in the browser terminal.',
    'Sign out from the Console account menu instead.',
  ].join('\n'),
  init: [
    'Project setup is not supported in the browser terminal.',
    'This project is already linked. appwrite.config.json is configured automatically.',
    'To scaffold resources locally, run appwrite init in a terminal on your machine.',
  ].join('\n'),
  pull: [
    'Pull is not supported in the browser terminal (interactive prompts are required).',
    'On your machine, run: appwrite pull all --all --force --no-code',
    'Here you can list resources instead, for example: appwrite functions list',
  ].join('\n'),
  push: [
    'Push and deploy are not supported in the browser terminal (interactive prompts are required).',
    'On your machine, run: appwrite push all --all --force',
    'Or push a single resource: appwrite push functions --all --force',
  ].join('\n'),
  deploy: [
    'appwrite deploy has been removed.',
    'Use appwrite push on your local machine instead.',
  ].join('\n'),
  run: [
    'Local function emulation is not supported in the browser terminal.',
    'It requires Docker and interactive prompts.',
    'On your machine, run: appwrite run functions --function-id <ID>',
  ].join('\n'),
  update: [
    'CLI self-update is not supported in the browser terminal.',
    'On your machine, run: appwrite update',
    'Or reinstall with npm install -g appwrite-cli',
  ].join('\n'),
}

/**
 * Returns a user-facing message when a command should not run in the browser shell.
 * Message may contain multiple lines separated by \\n.
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
