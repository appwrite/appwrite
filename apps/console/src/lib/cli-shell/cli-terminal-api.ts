import type { CliShellLine } from './types'
import type { SuggestionCommandLinkRange } from './cli-terminal-suggestion-links'
import { CLI_TERMINAL_URL_REGEX } from './cli-terminal-web-links'

export type CliTerminalApi = {
  write: (data: string, callback?: () => void) => void
  writeln: (data: string, callback?: () => void) => void
  clear: () => void
  focus: () => void
  /** Scroll the viewport to the active input line, then focus for typing. */
  focusInputLine?: () => void
  showInputPrompt?: () => void
  prepareInputLine?: (force?: boolean) => void
  resetForWelcome?: () => void
  markWelcomeComplete?: () => void
  clearScreen?: () => void
  /** Returns the current interactive prompt prefix (user@project $). */
  getPrompt?: () => string
  getLastCommand?: () => string | null
  getSelection?: () => string
  getBufferText?: () => string
  clearTerminalSearch?: () => void
  findNextMatch?: (query: string) => boolean
  findPreviousMatch?: (query: string) => boolean
  findFirstMatch?: (query: string) => boolean
  findLastMatch?: (query: string) => boolean
  openSearch?: () => void
  /** 1-based buffer cursor position (xterm link coordinates). */
  getBufferCursor?: () => { x: number; y: number }
  registerSuggestionCommandLinks?: (links: SuggestionCommandLinkRange[]) => void
}

/** Uses xterm `brightBlack`, mapped to `--muted-foreground` in the terminal theme. */
export const CLI_TERMINAL_MUTED = '\x1b[90m'
export const CLI_TERMINAL_RESET = '\x1b[0m'
export const CLI_TERMINAL_STDERR = '\x1b[31m'
export const CLI_TERMINAL_BLUE = '\x1b[34m'
export const CLI_TERMINAL_BRIGHT_BLUE = '\x1b[94m'
export const CLI_TERMINAL_CYAN = '\x1b[36m'
export const CLI_TERMINAL_GREEN = '\x1b[32m'
export const CLI_TERMINAL_YELLOW = '\x1b[33m'
/** Fallback prompt when account or project context is unavailable. */
export const CLI_TERMINAL_PROMPT = `${CLI_TERMINAL_CYAN}$${CLI_TERMINAL_RESET} `

export function sanitizeTerminalPromptSegment(value: string): string {
  const sanitized = value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^-+|-+$/g, '')
  return sanitized
}

export function resolveCliTerminalUsername(
  account: { name?: string | null; email?: string | null } | null | undefined,
): string {
  if (account?.name?.trim()) {
    return sanitizeTerminalPromptSegment(account.name) || 'user'
  }
  if (account?.email?.trim()) {
    const localPart = account.email.split('@')[0] ?? ''
    return sanitizeTerminalPromptSegment(localPart) || 'user'
  }
  return 'user'
}

export function resolveCliTerminalProjectLabel(
  project: { name?: string | null } | null | undefined,
  projectId: string,
): string {
  if (project?.name?.trim()) {
    return sanitizeTerminalPromptSegment(project.name) || projectId
  }
  return sanitizeTerminalPromptSegment(projectId) || 'project'
}

export function formatCliTerminalPrompt(options: {
  username: string
  projectName: string
}): string {
  const { username, projectName } = options
  return `${CLI_TERMINAL_GREEN}${username}@${projectName}${CLI_TERMINAL_RESET}${CLI_TERMINAL_CYAN}$${CLI_TERMINAL_RESET} `
}

/** Bright blue link text; underline appears on hover via the link provider. */
export function formatTerminalLink(text: string): string {
  return `${CLI_TERMINAL_BRIGHT_BLUE}${text}${CLI_TERMINAL_RESET}`
}

/** Color http(s) URLs in CLI output; click styling is handled by the link addon. */
export function linkifyTerminalText(text: string): string {
  return text.replace(CLI_TERMINAL_URL_REGEX, (url) => formatTerminalLink(url))
}

/** Buffers streamed CLI output by line so URLs are linkified once complete. */
export function createTerminalOutputLinkifier() {
  let pending = ''

  function writeLine(api: CliTerminalApi, line: string) {
    api.write(linkifyTerminalText(line))
  }

  return {
    write(api: CliTerminalApi | null, chunk: string) {
      if (!api || !chunk) return

      pending += chunk
      let newlineIndex = pending.indexOf('\n')
      while (newlineIndex !== -1) {
        const line = pending.slice(0, newlineIndex + 1)
        pending = pending.slice(newlineIndex + 1)
        writeLine(api, line)
        newlineIndex = pending.indexOf('\n')
      }
    },
    flush(api: CliTerminalApi | null) {
      if (!api || !pending) return
      writeLine(api, pending)
      pending = ''
    },
  }
}

function showInputPromptIfIdle(api: CliTerminalApi): void {
  api.showInputPrompt?.()
}

function writeSegment(api: CliTerminalApi, data: string): Promise<void> {
  return new Promise((resolve) => {
    api.write(data, () => resolve())
  })
}

function isValidSuggestionLinkRange(
  start: { x: number; y: number },
  end: { x: number; y: number },
): boolean {
  return end.y > start.y || (end.y === start.y && end.x > start.x)
}

/** Writes the Try suggestions line and registers click targets after xterm flushes. */
export async function writeCliShellSuggestions(
  api: CliTerminalApi,
  commands: readonly string[],
): Promise<void> {
  const links: SuggestionCommandLinkRange[] = []

  await writeSegment(api, `${CLI_TERMINAL_BLUE}Try:${CLI_TERMINAL_RESET}  `)

  for (let index = 0; index < commands.length; index++) {
    const command = commands[index]
    const start = api.getBufferCursor?.()
    await writeSegment(
      api,
      `${CLI_TERMINAL_CYAN}${command}${CLI_TERMINAL_RESET}`,
    )
    const end = api.getBufferCursor?.()
    if (start && end && isValidSuggestionLinkRange(start, end)) {
      links.push({ command, start, end })
    }
    if (index < commands.length - 1) {
      await writeSegment(api, `${CLI_TERMINAL_MUTED}, ${CLI_TERMINAL_RESET}`)
    }
  }

  await writeSegment(api, `${CLI_TERMINAL_RESET}\n`)
  api.registerSuggestionCommandLinks?.(links)
}

export function writeCliShellLine(
  api: CliTerminalApi | null,
  line: CliShellLine,
  options?: { showPromptAfter?: boolean },
): void {
  if (!api) return

  switch (line.type) {
    case 'system':
      api.writeln(`${CLI_TERMINAL_MUTED}${line.text}${CLI_TERMINAL_RESET}`)
      break
    case 'rich':
      api.writeln(line.text)
      break
    case 'stderr':
      api.writeln(`${CLI_TERMINAL_STDERR}${line.text}${CLI_TERMINAL_RESET}`)
      break
    case 'stdout':
      api.writeln(linkifyTerminalText(line.text))
      break
    case 'command': {
      const prompt = api.getPrompt?.() ?? CLI_TERMINAL_PROMPT
      api.writeln(`${prompt}${line.text}`)
      break
    }
    case 'suggestions': {
      void writeCliShellSuggestions(api, line.commands).then(() => {
        if (options?.showPromptAfter) {
          showInputPromptIfIdle(api)
        }
      })
      return
    }
  }

  if (options?.showPromptAfter) {
    showInputPromptIfIdle(api)
  }
}

export function writeCliTerminalRaw(
  api: CliTerminalApi | null,
  chunk: string,
): void {
  if (!api || !chunk) return

  const lines = chunk.split('\n')
  const tail = lines.pop()

  for (const line of lines) {
    api.write(linkifyTerminalText(`${line}\n`))
  }

  if (tail) {
    api.write(linkifyTerminalText(tail))
  }
}
