import type {
  IDisposable,
  IDecoration,
  ILink,
  ITerminalAddon,
  Terminal,
} from '@xterm/xterm'

/** Same URL matching rules as @xterm/addon-web-links. */
export const CLI_TERMINAL_URL_REGEX =
  /https?:\/\/[^\s"'!*(){}|\\\^<>`]*[^\s"':,.!?{}|\\\^~\[\]`()<>]/g

type UnderlineEntry = {
  decoration: IDecoration
  element: HTMLElement | null
}

function isHttpUrl(url: string): boolean {
  try {
    const parsed = new URL(url)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}

function linkKey(range: ILink['range'], text: string): string {
  return `${range.start.y}:${range.start.x}:${text}`
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
      element.className = 'cli-terminal-url-underline'
      element.style.pointerEvents = 'none'
    })

    marker.onDispose(() => {
      underlineByKey.delete(key)
      decoration.dispose()
    })
  }

  function scanBufferLine(lineIndex: number): void {
    if (!terminal || lineIndex < 0) return

    const line = terminal.buffer.active.getLine(lineIndex)
    if (!line) return

    const lineText = line.translateToString(true)
    const y = lineIndex + 1

    for (const { url, startX } of findUrls(lineText)) {
      const range = {
        start: { x: startX, y },
        end: { x: startX + url.length, y },
      }
      ensureUnderlineDecoration(range, url)
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
              const key = linkKey(range, url)

              ensureUnderlineDecoration(range, url)

              links.push({
                text: url,
                range,
                decorations: { underline: false, pointerCursor: true },
                activate: (event) => {
                  handler(event, url)
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
