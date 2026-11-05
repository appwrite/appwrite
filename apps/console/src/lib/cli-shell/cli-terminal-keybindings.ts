export type TerminalKeyAction =
  | { type: 'cursor-start' }
  | { type: 'cursor-end' }
  | { type: 'cursor-left' }
  | { type: 'cursor-right' }
  | { type: 'cursor-word-left' }
  | { type: 'cursor-word-right' }
  | { type: 'history-prev' }
  | { type: 'history-next' }
  | { type: 'delete-backward' }
  | { type: 'delete-forward' }
  | { type: 'delete-word-backward' }
  | { type: 'kill-line-before' }
  | { type: 'kill-line-after' }
  | { type: 'clear-line' }
  | { type: 'clear-screen' }
  | { type: 'submit' }
  | { type: 'tab' }
  | { type: 'insert'; text: string }

const KEY_BINDINGS: ReadonlyArray<readonly [string, TerminalKeyAction]> = [
  // Navigation
  ['\x01', { type: 'cursor-start' }], // Ctrl+A
  ['\x05', { type: 'cursor-end' }], // Ctrl+E
  ['\x1b[H', { type: 'cursor-start' }], // Home
  ['\x1b[1~', { type: 'cursor-start' }], // Home (xterm)
  ['\x1bOH', { type: 'cursor-start' }], // Home (application)
  ['\x1b[F', { type: 'cursor-end' }], // End
  ['\x1b[4~', { type: 'cursor-end' }], // End (xterm)
  ['\x1bOF', { type: 'cursor-end' }], // End (application)
  ['\x1b[A', { type: 'history-prev' }], // Up
  ['\x1b[B', { type: 'history-next' }], // Down
  ['\x1b[C', { type: 'cursor-right' }], // Right
  ['\x1b[D', { type: 'cursor-left' }], // Left
  ['\x1b[1;3D', { type: 'cursor-word-left' }], // Alt+Left
  ['\x1b[1;5D', { type: 'cursor-word-left' }], // Ctrl+Left
  ['\x1b b', { type: 'cursor-word-left' }], // Option+Left (macOS readline)
  ['\x1b[1;3C', { type: 'cursor-word-right' }], // Alt+Right
  ['\x1b[1;5C', { type: 'cursor-word-right' }], // Ctrl+Right
  ['\x1b f', { type: 'cursor-word-right' }], // Option+Right (macOS readline)
  // Editing
  ['\u007f', { type: 'delete-backward' }], // Backspace
  ['\b', { type: 'delete-backward' }],
  ['\x1b\x7f', { type: 'delete-word-backward' }], // Alt+Backspace
  ['\x17', { type: 'delete-word-backward' }], // Ctrl+W
  ['\x1b[3~', { type: 'delete-forward' }], // Delete
  ['\x04', { type: 'delete-forward' }], // Ctrl+D
  ['\x15', { type: 'kill-line-before' }], // Ctrl+U
  ['\x0b', { type: 'kill-line-after' }], // Ctrl+K
  ['\x03', { type: 'clear-line' }], // Ctrl+C
  ['\x0c', { type: 'clear-screen' }], // Ctrl+L
  // Actions
  ['\r', { type: 'submit' }],
  ['\r\n', { type: 'submit' }],
  ['\t', { type: 'tab' }],
]

const KEY_BINDING_MAP = new Map<string, TerminalKeyAction>(KEY_BINDINGS)

export function resolveTerminalKeyAction(
  data: string,
): TerminalKeyAction | null {
  if (data === '\n') {
    return { type: 'submit' }
  }

  const binding = KEY_BINDING_MAP.get(data)
  if (binding) return binding

  if (data.startsWith('\x1b')) {
    return null
  }

  if (data.length === 1 && data >= ' ') {
    return { type: 'insert', text: data }
  }

  return null
}

/**
 * Consume a terminal escape sequence starting at `start` without inserting its
 * payload into the interactive input buffer.
 */
export function consumeTerminalEscapeSequence(
  data: string,
  start: number,
): number {
  if (data[start] !== '\x1b') return start + 1
  if (start + 1 >= data.length) return start + 1

  const second = data[start + 1]

  if (second === '[') {
    let index = start + 2
    while (index < data.length) {
      const code = data.charCodeAt(index)
      if (code >= 0x40 && code <= 0x7e) {
        return index + 1
      }
      index += 1
    }
    return data.length
  }

  if (second === ']') {
    let index = start + 2
    while (index < data.length) {
      if (data[index] === '\x07') return index + 1
      if (data[index] === '\x1b' && data[index + 1] === '\\') {
        return index + 2
      }
      index += 1
    }
    return data.length
  }

  if (second === 'O' && start + 2 < data.length) {
    return start + 3
  }

  if (second === 'P') {
    let index = start + 2
    while (index < data.length) {
      if (data[index] === '\x1b' && data[index + 1] === '\\') {
        return index + 2
      }
      index += 1
    }
    return data.length
  }

  return start + 2
}

export function findWordBoundaryLeft(text: string, pos: number): number {
  if (pos <= 0) return 0

  let index = pos
  while (index > 0 && /\s/.test(text[index - 1] ?? '')) {
    index -= 1
  }
  while (index > 0 && !/\s/.test(text[index - 1] ?? '')) {
    index -= 1
  }

  return index
}

export function findWordBoundaryRight(text: string, pos: number): number {
  if (pos >= text.length) return text.length

  let index = pos
  while (index < text.length && /\s/.test(text[index] ?? '')) {
    index += 1
  }
  while (index < text.length && !/\s/.test(text[index] ?? '')) {
    index += 1
  }

  return index
}
