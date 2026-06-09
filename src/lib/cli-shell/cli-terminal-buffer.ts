import type { Terminal } from '@xterm/xterm'

/** Strip ANSI escape sequences from terminal text. */
export function stripAnsi(text: string): string {
  return text.replace(/\x1b\[[0-9;]*[a-zA-Z]/g, '').replace(/\x1b\].*?(?:\x07|\x1b\\)/g, '')
}

/** Read scrollback buffer as plain text (no ANSI). */
export function getTerminalBufferText(terminal: Terminal): string {
  const buffer = terminal.buffer.active
  const lines: string[] = []

  for (let i = 0; i < buffer.length; i++) {
    const line = buffer.getLine(i)
    if (!line) continue
    lines.push(stripAnsi(line.translateToString(false)))
  }

  return lines.join('\n').replace(/\s+$/g, '')
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
