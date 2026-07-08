/** Display metadata for terminal shortcuts shown in the shortcuts reference UI. */
export type TerminalShortcutRef = {
  id: string
  description: string
  /** Shortcut string in the same format as command-center entries (e.g. `ctrl+backquote`, `ctrl+u`). */
  raw: string
}

/** Display string for shortcuts UI (mod resolves to ⌘ on Mac, Ctrl on Windows). */
export const CLI_SHELL_TOGGLE_SHORTCUT_RAW = 'mod+;'

/** Key combos registered for the terminal toggle (mod is not parsed by useKeyboardShortcut). */
export const CLI_SHELL_TOGGLE_SHORTCUT_COMBOS = [
  'meta+;',
  'control+;',
] as const

/** Console-level shortcut for opening/closing the project terminal panel. */
export const CLI_SHELL_FULLSCREEN_SHORTCUT_RAW = 'mod+enter'

export const CLI_SHELL_FULLSCREEN_SHORTCUT_COMBOS = [
  'meta+enter',
  'control+enter',
] as const

export const CLI_SHELL_SEARCH_SHORTCUT_RAW = 'mod+f'

export const CLI_SHELL_SEARCH_SHORTCUT_COMBOS = [
  'meta+f',
  'control+f',
] as const

export const CLI_SHELL_NEW_TERMINAL_SHORTCUT_RAW = 'mod+shift+;'

export const CLI_SHELL_NEW_TERMINAL_SHORTCUT_COMBOS = [
  'meta+shift+;',
  'control+shift+;',
] as const

export const CLI_SHELL_CONSOLE_SHORTCUTS: readonly TerminalShortcutRef[] = [
  {
    id: 'terminal.toggle',
    description: 'Toggle terminal',
    raw: CLI_SHELL_TOGGLE_SHORTCUT_RAW,
  },
  {
    id: 'terminal.fullscreen',
    description: 'Enter full screen',
    raw: CLI_SHELL_FULLSCREEN_SHORTCUT_RAW,
  },
  {
    id: 'terminal.search',
    description: 'Search output...',
    raw: CLI_SHELL_SEARCH_SHORTCUT_RAW,
  },
  {
    id: 'terminal.new',
    description: 'New terminal',
    raw: CLI_SHELL_NEW_TERMINAL_SHORTCUT_RAW,
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
    description: 'Clear input line or cancel command',
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
