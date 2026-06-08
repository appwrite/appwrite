import type {
  IDisposable,
  IDecoration,
  ILink,
  ITerminalAddon,
  Terminal,
} from '@xterm/xterm'

type UnderlineEntry = {
  decoration: IDecoration
  element: HTMLElement | null
}

function linkKey(range: ILink['range'], text: string): string {
  return `${range.start.y}:${range.start.x}:${text}`
}

function findCommandsInLine(
  lineText: string,
  commands: readonly string[],
): Array<{ command: string; startX: number }> {
  const matches: Array<{ command: string; startX: number }> = []

  for (const command of commands) {
    let from = 0
    while (from <= lineText.length) {
      const index = lineText.indexOf(command, from)
      if (index === -1) break
      matches.push({ command, startX: index + 1 })
      from = index + command.length
    }
  }

  return matches.sort((a, b) => a.startX - b.startX)
}

export function createCliTerminalSuggestionLinksAddon(
  commands: readonly string[],
  handler: (event: MouseEvent, command: string) => void,
): ITerminalAddon & {
  scanBufferLine: (lineIndex: number) => void
  scanLastWrittenLine: () => void
} {
  let terminal: Terminal | null = null
  const underlineByKey = new Map<string, UnderlineEntry>()
  const disposables: IDisposable[] = []

  function setUnderlineVisible(key: string, visible: boolean) {
    const entry = underlineByKey.get(key)
    if (!entry?.element) return
    entry.element.style.borderBottomWidth = visible ? '1px' : '0'
  }

  function ensureUnderlineDecoration(
    range: ILink['range'],
    text: string,
  ): void {
    if (!terminal) return

    const key = linkKey(range, text)
    if (underlineByKey.has(key)) return

    const buffer = terminal.buffer.active
    const lineIndex = range.start.y - 1
    const markerOffset = lineIndex - (buffer.baseY + buffer.cursorY)
    const marker = terminal.registerMarker(markerOffset)
    if (!marker) return

    const decoration = terminal.registerDecoration({
      marker,
      x: range.start.x - 1,
      width: range.end.x - range.start.x,
    })
    if (!decoration) return

    const entry: UnderlineEntry = { decoration, element: null }
    underlineByKey.set(key, entry)

    decoration.onRender((element) => {
      entry.element = element
      element.className = 'cli-terminal-suggestion-underline'
      element.style.pointerEvents = 'none'
    })

    marker.onDispose(() => {
      underlineByKey.delete(key)
      decoration.dispose()
    })
  }

  function scanBufferLine(lineIndex: number): void {
    if (!terminal || lineIndex < 0 || commands.length === 0) return

    const line = terminal.buffer.active.getLine(lineIndex)
    if (!line) return

    const lineText = line.translateToString(true)
    const y = lineIndex + 1

    for (const { command, startX } of findCommandsInLine(lineText, commands)) {
      const range = {
        start: { x: startX, y },
        end: { x: startX + command.length, y },
      }
      ensureUnderlineDecoration(range, command)
    }
  }

  function scanLastWrittenLine(): void {
    if (!terminal) return
    const buffer = terminal.buffer.active
    const lineIndex = buffer.baseY + buffer.cursorY - 1
    scanBufferLine(lineIndex)
  }

  return {
    activate(nextTerminal) {
      terminal = nextTerminal
      terminal.options.allowProposedApi = true

      disposables.push(
        terminal.registerLinkProvider({
          provideLinks(y, callback) {
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
              const key = linkKey(range, command)

              ensureUnderlineDecoration(range, command)

              links.push({
                text: command,
                range,
                decorations: { underline: false, pointerCursor: true },
                activate: (event) => {
                  handler(event, command)
                  event.preventDefault()
                },
                hover: () => setUnderlineVisible(key, false),
                leave: () => setUnderlineVisible(key, true),
              })
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

      for (const { decoration } of underlineByKey.values()) {
        decoration.dispose()
      }
      underlineByKey.clear()
      terminal = null
    },
    scanBufferLine,
    scanLastWrittenLine,
  }
}
