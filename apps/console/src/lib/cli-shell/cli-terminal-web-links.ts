import type {
  IDisposable,
  ILink,
  ITerminalAddon,
  Terminal,
} from '@xterm/xterm'
import {
  registerCliTerminalLinkCursorCleanup,
  withCliTerminalLinkCursor,
} from './cli-terminal-link-cursor'

/** Same URL matching rules as @xterm/addon-web-links. */
export const CLI_TERMINAL_URL_REGEX =
  /https?:\/\/[^\s"'!*(){}|\\\^<>`]*[^\s"':,.!?{}|\\\^~\[\]`()<>]/g

function isHttpUrl(url: string): boolean {
  try {
    const parsed = new URL(url)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}

function findUrls(lineText: string): Array<{ url: string; startX: number }> {
  const matches: Array<{ url: string; startX: number }> = []
  const regex = new RegExp(CLI_TERMINAL_URL_REGEX.source, 'g')
  let match: RegExpExecArray | null

  while ((match = regex.exec(lineText))) {
    const url = match[0]
    if (!isHttpUrl(url)) continue
    matches.push({ url, startX: match.index + 1 })
  }

  return matches
}

export function createCliTerminalWebLinksAddon(
  handler: (event: MouseEvent, uri: string) => void,
): ITerminalAddon {
  let terminal: Terminal | null = null
  const disposables: IDisposable[] = []

  return {
    activate(nextTerminal) {
      terminal = nextTerminal
      disposables.push(registerCliTerminalLinkCursorCleanup(terminal))

      disposables.push(
        terminal.registerLinkProvider({
          provideLinks(y, callback) {
            if (!terminal) {
              callback(undefined)
              return
            }

            const line = terminal.buffer.active.getLine(y - 1)
            if (!line) {
              callback(undefined)
              return
            }

            const lineText = line.translateToString(true)
            const links: ILink[] = []

            for (const { url, startX } of findUrls(lineText)) {
              const range = {
                start: { x: startX, y },
                end: { x: startX + url.length, y },
              }

              links.push(
                withCliTerminalLinkCursor(terminal, {
                  text: url,
                  range,
                  decorations: { underline: true, pointerCursor: true },
                  activate: (event) => {
                    handler(event, url)
                    event.preventDefault()
                  },
                }),
              )
            }

            callback(links.length > 0 ? links : undefined)
          },
        }),
      )
    },
    dispose() {
      for (const disposable of disposables) {
        disposable.dispose()
      }
      disposables.length = 0
      terminal = null
    },
  }
}
