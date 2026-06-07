import type { CliShellLine } from './types'
import { CLI_TERMINAL_URL_REGEX } from './cli-terminal-web-links'

export type CliTerminalApi = {
  write: (data: string, callback?: () => void) => void
  writeln: (data: string, callback?: () => void) => void
  clear: () => void
  focus: () => void
  showInputPrompt?: () => void
  clearScreen?: () => void
  /** Called after each completed output line to register link underlines. */
  afterOutputLine?: () => void
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
export const CLI_TERMINAL_PROMPT = `${CLI_TERMINAL_CYAN}$${CLI_TERMINAL_RESET} `

/** Bright blue link text; solid underline is drawn by the terminal link addon. */
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
    api.write(linkifyTerminalText(line), () => {
      api.afterOutputLine?.()
    })
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
      api.writeln(line.text, () => {
        api.afterOutputLine?.()
      })
      break
    case 'stderr':
      api.writeln(`${CLI_TERMINAL_STDERR}${line.text}${CLI_TERMINAL_RESET}`)
      break
    case 'stdout':
      api.writeln(linkifyTerminalText(line.text), () => {
        api.afterOutputLine?.()
      })
      break
    case 'command':
      api.writeln(`$ ${line.text}`)
      break
    case 'suggestions': {
      const parts = line.commands
        .map(
          (command) =>
            `${CLI_TERMINAL_CYAN}${command}${CLI_TERMINAL_RESET}`,
        )
        .join(`${CLI_TERMINAL_MUTED}, ${CLI_TERMINAL_RESET}`)
      api.writeln(
        `${CLI_TERMINAL_BLUE}Try:${CLI_TERMINAL_RESET} ${parts}${CLI_TERMINAL_RESET}`,
      )
      break
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
    api.write(linkifyTerminalText(`${line}\n`), () => {
      api.afterOutputLine?.()
    })
  }

  if (tail) {
    api.write(linkifyTerminalText(tail), () => {
      api.afterOutputLine?.()
    })
  }
}
