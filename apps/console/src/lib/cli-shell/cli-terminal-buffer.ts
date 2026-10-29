import type { Terminal } from '@xterm/xterm'

/** Strip ANSI escape sequences from terminal text. */
export function stripAnsi(text: string): string {
  return text
    .replace(/\x1b\[[0-9;]*[a-zA-Z]/g, '')
    .replace(/\x1b\].*?(?:\x07|\x1b\\)/g, '')
}

function normalizeTerminalLineText(text: string): string {
  return stripAnsi(text).replace(/\u00a0/g, ' ').replace(/\s+$/g, '')
}

function readLineText(line: NonNullable<
  ReturnType<Terminal['buffer']['active']['getLine']>
>): string {
  let text = ''
  for (let x = 0; x < line.length; x++) {
    text += line.getCell(x)?.getChars() ?? ''
  }
  if (!text) {
    text = line.translateToString(false)
  }
  return normalizeTerminalLineText(text)
}

function readBufferLines(terminal: Terminal): string {
  const buffer = terminal.buffer.active
  const lines: string[] = []
  const lineCount = Math.max(buffer.length, buffer.baseY + terminal.rows)

  for (let y = 0; y < lineCount; y++) {
    const line = buffer.getLine(y)
    lines.push(line ? readLineText(line) : '')
  }

  return lines.join('\n').replace(/\s+$/g, '')
}

function readTerminalDomText(terminal: Terminal): string {
  const element = terminal.element
  if (!element) return ''

  const rowElements = element.querySelectorAll('.xterm-row')
  if (rowElements.length > 0) {
    const lines: string[] = []
    rowElements.forEach((row) => {
      lines.push(normalizeTerminalLineText(row.textContent ?? ''))
    })
    return lines.join('\n').replace(/\s+$/g, '')
  }

  const rows = element.querySelector('.xterm-rows')
  if (!rows) return ''

  return normalizeTerminalLineText(rows.textContent ?? '')
}

/** Read scrollback buffer as plain text (no ANSI). */
export function getTerminalBufferText(terminal: Terminal): string {
  const fromBuffer = readBufferLines(terminal)
  if (fromBuffer.trim()) return fromBuffer

  return readTerminalDomText(terminal)
}

export function downloadTextFile(filename: string, content: string): void {
  const blob = new Blob([content], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}
