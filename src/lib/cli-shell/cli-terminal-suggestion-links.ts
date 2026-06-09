import type {
  IDisposable,
  ILink,
  ITerminalAddon,
  Terminal,
} from '@xterm/xterm'
import { withCliTerminalLinkCursor } from './cli-terminal-link-cursor'

function findCommandsInLine(
  lineText: string,
  commands: readonly string[],
): Array<{ command: string; startX: number }> {
  if (!lineText.includes('Try:')) return []

  const sorted = [...commands].sort((a, b) => b.length - a.length)
  const matches: Array<{ command: string; startX: number }> = []
  const occupied = new Set<number>()

  for (const command of sorted) {
    let from = 0
    while (from <= lineText.length) {
      const index = lineText.indexOf(command, from)
      if (index === -1) break

      const end = index + command.length
      let overlaps = false
      for (let i = index; i < end; i++) {
        if (occupied.has(i)) overlaps = true
      }

      if (!overlaps) {
        for (let i = index; i < end; i++) occupied.add(i)
        matches.push({ command, startX: index + 1 })
      }

      from = index + 1
    }
  }

  return matches.sort((a, b) => a.startX - b.startX)
}

export function createCliTerminalSuggestionLinksAddon(
  getCommands: () => readonly string[],
  handler: (event: MouseEvent, command: string) => void,
): ITerminalAddon {
  let terminal: Terminal | null = null
  const disposables: IDisposable[] = []

  return {
    activate(nextTerminal) {
      terminal = nextTerminal

      disposables.push(
        terminal.registerLinkProvider({
          provideLinks(y, callback) {
            const commands = getCommands()
            if (!terminal || commands.length === 0) {
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

            for (const { command, startX } of findCommandsInLine(
              lineText,
              commands,
            )) {
              const range = {
                start: { x: startX, y },
                end: { x: startX + command.length, y },
              }

              links.push(
                withCliTerminalLinkCursor(terminal, {
                  text: command,
                  range,
                  decorations: { underline: true, pointerCursor: true },
                  activate: (event) => {
                    handler(event, command)
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
