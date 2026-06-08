/** Display metadata for terminal shortcuts shown in the shortcuts reference UI. */
export type TerminalShortcutRef = {
  id: string
  description: string
  /** Shortcut string in the same format as command-center entries (e.g. `mod+` `, `ctrl+u`). */
  raw: string
}

export const CLI_SHELL_TOGGLE_SHORTCUT_RAW = 'mod+`'

/** Console-level shortcut for opening/closing the project terminal panel. */
export const CLI_SHELL_CONSOLE_SHORTCUTS: readonly TerminalShortcutRef[] = [
  {
    id: 'terminal.toggle',
    description: 'Toggle terminal',
    raw: CLI_SHELL_TOGGLE_SHORTCUT_RAW,
  },
]

/** Shortcuts that apply while typing in the built-in terminal input. */
export const CLI_TERMINAL_INPUT_SHORTCUTS: readonly TerminalShortcutRef[] = [
  { id: 'terminal.home', description: 'Move to start of line', raw: 'home' },
  {
    id: 'terminal.ctrl-a',
    description: 'Move to start of line',
    raw: 'ctrl+a',
  },
  { id: 'terminal.end', description: 'Move to end of line', raw: 'end' },
  {
    id: 'terminal.ctrl-e',
    description: 'Move to end of line',
    raw: 'ctrl+e',
  },
  {
    id: 'terminal.word-left',
    description: 'Move to previous word',
    raw: 'alt+left',
  },
  {
    id: 'terminal.word-right',
    description: 'Move to next word',
    raw: 'alt+right',
  },
  {
    id: 'terminal.history-up',
    description: 'Previous command',
    raw: 'up',
  },
  {
    id: 'terminal.history-down',
    description: 'Next command',
    raw: 'down',
  },
  {
    id: 'terminal.delete-word',
    description: 'Delete word before cursor',
    raw: 'ctrl+w',
  },
  {
    id: 'terminal.delete-word-alt',
    description: 'Delete word before cursor',
    raw: 'alt+backspace',
  },
  {
    id: 'terminal.kill-before',
    description: 'Clear from cursor to start of line',
    raw: 'ctrl+u',
  },
  {
    id: 'terminal.kill-after',
    description: 'Clear from cursor to end of line',
    raw: 'ctrl+k',
  },
  {
    id: 'terminal.clear-line',
    description: 'Clear input line',
    raw: 'ctrl+c',
  },
  {
    id: 'terminal.delete-forward',
    description: 'Delete character at cursor',
    raw: 'delete',
  },
  {
    id: 'terminal.tab',
    description: 'Tab completion',
    raw: 'tab',
  },
]
