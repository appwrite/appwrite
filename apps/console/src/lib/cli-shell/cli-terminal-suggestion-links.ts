import type {
  IDisposable,
  ILink,
  ITerminalAddon,
  Terminal,
} from '@xterm/xterm'
import { withCliTerminalLinkCursor } from './cli-terminal-link-cursor'

export type SuggestionCommandLinkRange = {
  command: string
  start: { x: number; y: number }
  end: { x: number; y: number }
}

type SuggestionLinksController = {
  addon: ITerminalAddon
  setSuggestionCommandLinks: (links: SuggestionCommandLinkRange[]) => void
  clearSuggestionCommandLinks: () => void
}

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

function linksForStoredRangeOnLine(
  terminal: Terminal,
  y: number,
  range: SuggestionCommandLinkRange,
  handler: (event: MouseEvent, command: string) => void,
): ILink[] {
  const { command, start, end } = range
  if (y < start.y || y > end.y) return []

  const linkStartX = y === start.y ? start.x : 1
  const linkEndX = y === end.y ? end.x : terminal.cols

  if (linkEndX <= linkStartX) return []

  const linkRange = {
    start: { x: linkStartX, y },
    end: { x: linkEndX, y },
  }

  return [
    withCliTerminalLinkCursor(terminal, {
      text: command,
      range: linkRange,
      decorations: { underline: true, pointerCursor: true },
      activate: (event) => {
        handler(event, command)
        event.preventDefault()
      },
    }),
  ]
}

function linksFromLineText(
  terminal: Terminal,
  y: number,
  lineText: string,
  commands: readonly string[],
  handler: (event: MouseEvent, command: string) => void,
): ILink[] {
  const links: ILink[] = []

  for (const { command, startX } of findCommandsInLine(lineText, commands)) {
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

  return links
}

export function createCliTerminalSuggestionLinksAddon(
  getCommands: () => readonly string[],
  handler: (event: MouseEvent, command: string) => void,
): SuggestionLinksController {
  let terminal: Terminal | null = null
  let storedRanges: SuggestionCommandLinkRange[] = []
  const disposables: IDisposable[] = []

  const addon: ITerminalAddon = {
    activate(nextTerminal) {
      terminal = nextTerminal

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

            if (storedRanges.length > 0) {
              for (const range of storedRanges) {
                links.push(
                  ...linksForStoredRangeOnLine(terminal, y, range, handler),
                )
              }
            } else if (lineText.includes('Try:')) {
              links.push(
                ...linksFromLineText(
                  terminal,
                  y,
                  lineText,
                  getCommands(),
                  handler,
                ),
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
      storedRanges = []
      terminal = null
    },
  }

  return {
    addon,
    setSuggestionCommandLinks: (links) => {
      storedRanges = links
    },
    clearSuggestionCommandLinks: () => {
      storedRanges = []
    },
  }
}
