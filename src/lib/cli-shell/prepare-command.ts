import { CLI_APPWRITE_BIN } from './constants'

const PULL_SUBCOMMANDS = new Set([
  'all',
  'settings',
  'function',
  'functions',
  'site',
  'sites',
  'collection',
  'collections',
  'table',
  'tables',
  'bucket',
  'buckets',
  'team',
  'teams',
  'webhook',
  'webhooks',
  'topic',
  'topics',
])

/**
 * `pull all --force` still prompts for function/site code via inquirer, which breaks
 * in the browser shell. Run each resource pull with flags that skip every prompt.
 */
const NON_INTERACTIVE_PULL_ALL_STEPS = [
  'pull settings',
  'pull functions --all --force --no-code',
  'pull sites --all --force --no-code',
  'pull tables --all --force',
  'pull buckets --all --force',
  'pull teams --all --force',
  'pull webhooks --all --force',
  'pull topics --all --force',
] as const

function buildNonInteractivePullAllCommand(extraFlags = ''): string {
  const suffix = extraFlags ? ` ${extraFlags}` : ''
  return NON_INTERACTIVE_PULL_ALL_STEPS.map(
    (step) => `${CLI_APPWRITE_BIN} ${step}${suffix}`,
  ).join(' && ')
}

function extractPullAllExtraFlags(remainder: string): string {
  return remainder.replace(/^all\b/i, '').trim()
}

/**
 * Bare `appwrite pull` / `appwrite pull all` open inquirer prompts that cannot
 * work in the browser terminal (no TTY/stdin).
 */
function preparePullCommand(body: string): string | null {
  const match = body.match(/^pull(?:\s+(.*))?$/)
  if (!match) return null

  const remainder = match[1]?.trim() ?? ''
  if (!remainder) {
    return buildNonInteractivePullAllCommand()
  }

  const firstToken = remainder.split(/\s+/)[0]?.toLowerCase()
  if (firstToken === 'all') {
    return buildNonInteractivePullAllCommand(extractPullAllExtraFlags(remainder))
  }

  if (firstToken && PULL_SUBCOMMANDS.has(firstToken)) {
    return null
  }

  if (remainder.startsWith('-')) {
    return buildNonInteractivePullAllCommand(remainder)
  }

  return null
}

/** Map user input to a shell command with a reliable Appwrite CLI binary path. */
export function prepareCliCommand(rawCommand: string): string {
  const trimmed = rawCommand.trim()
  if (!trimmed) return trimmed

  if (trimmed === 'appwrite') {
    return CLI_APPWRITE_BIN
  }

  if (trimmed.startsWith('appwrite ')) {
    const body = trimmed.slice('appwrite'.length).trimStart()
    const pullCommand = preparePullCommand(body)
    if (pullCommand) return pullCommand
    return `${CLI_APPWRITE_BIN}${trimmed.slice('appwrite'.length)}`
  }

  return trimmed
}
