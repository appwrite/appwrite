/** Display metadata for Postgres SQL editor shortcuts. */
export type PostgresSqlEditorShortcutRef = {
  id: string
  description: string
  /** Shortcut string in the same format as command-center entries (e.g. `mod+enter`). */
  raw: string
}

export const POSTGRES_SQL_RUN_SHORTCUT_RAW = 'mod+enter'

export const POSTGRES_SQL_RUN_SHORTCUT_COMBOS = [
  'meta+enter',
  'control+enter',
] as const

export const POSTGRES_SQL_EXPLAIN_SHORTCUT_RAW = 'mod+shift+e'

export const POSTGRES_SQL_EXPLAIN_SHORTCUT_COMBOS = [
  'meta+shift+e',
  'control+shift+e',
] as const

export const POSTGRES_SQL_SAVE_SHORTCUT_RAW = 'mod+s'

export const POSTGRES_SQL_SAVE_SHORTCUT_COMBOS = [
  'meta+s',
  'control+s',
] as const

/** Format document in the Postgres SQL editor (Shift+Alt+F). */
export const POSTGRES_SQL_FORMAT_SHORTCUT_RAW = 'shift+alt+f'

export const POSTGRES_SQL_FORMAT_SHORTCUT_COMBOS = [
  'shift+alt+f',
] as const

export const POSTGRES_SQL_UNDO_SHORTCUT_RAW = 'mod+z'

export const POSTGRES_SQL_REDO_SHORTCUT_RAW = 'mod+shift+z'

/** Control (not Command) so Mac app switching (⌘Tab) stays untouched. */
export const POSTGRES_SQL_NEXT_TAB_SHORTCUT_RAW = 'ctrl+tab'

export const POSTGRES_SQL_NEXT_TAB_SHORTCUT_COMBOS = [
  'control+tab',
] as const

export const POSTGRES_SQL_PREV_TAB_SHORTCUT_RAW = 'ctrl+shift+tab'

export const POSTGRES_SQL_PREV_TAB_SHORTCUT_COMBOS = [
  'control+shift+tab',
] as const

export const POSTGRES_SQL_NEW_TAB_SHORTCUT_RAW = 'mod+t'

export const POSTGRES_SQL_NEW_TAB_SHORTCUT_COMBOS = [
  'meta+t',
  'control+t',
] as const

export const POSTGRES_SQL_CLOSE_TAB_SHORTCUT_RAW = 'mod+w'

export const POSTGRES_SQL_CLOSE_TAB_SHORTCUT_COMBOS = [
  'meta+w',
  'control+w',
] as const

export const POSTGRES_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW = 'mod+shift+p'

export const POSTGRES_SQL_JUMP_TAB_PICKER_SHORTCUT_COMBOS = [
  'meta+shift+p',
  'control+shift+p',
] as const

export const POSTGRES_SQL_JUMP_TAB_DIGIT_SHORTCUTS: readonly PostgresSqlEditorShortcutRef[] =
  Array.from({ length: 8 }, (_, index) => ({
    id: `postgres-sql.jump-tab-${index + 1}`,
    description: `Jump to tab ${index + 1}`,
    raw: `mod+${index + 1}`,
  }))

export const POSTGRES_SQL_EDITOR_SHORTCUTS: readonly PostgresSqlEditorShortcutRef[] =
  [
    {
      id: 'postgres-sql.run',
      description: 'Run',
      raw: POSTGRES_SQL_RUN_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.explain',
      description: 'Explain',
      raw: POSTGRES_SQL_EXPLAIN_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.save',
      description: 'Save query',
      raw: POSTGRES_SQL_SAVE_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.format',
      description: 'Format SQL',
      raw: POSTGRES_SQL_FORMAT_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.undo',
      description: 'Undo',
      raw: POSTGRES_SQL_UNDO_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.redo',
      description: 'Redo',
      raw: POSTGRES_SQL_REDO_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.next-tab',
      description: 'Next query tab',
      raw: POSTGRES_SQL_NEXT_TAB_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.prev-tab',
      description: 'Previous query tab',
      raw: POSTGRES_SQL_PREV_TAB_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.new-tab',
      description: 'New query tab',
      raw: POSTGRES_SQL_NEW_TAB_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.close-tab',
      description: 'Close query tab',
      raw: POSTGRES_SQL_CLOSE_TAB_SHORTCUT_RAW,
    },
    {
      id: 'postgres-sql.jump-tab-picker',
      description: 'Go to query tab',
      raw: POSTGRES_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW,
    },
    ...POSTGRES_SQL_JUMP_TAB_DIGIT_SHORTCUTS,
    {
      id: 'postgres-sql.jump-tab-last',
      description: 'Jump to last tab',
      raw: 'mod+9',
    },
  ]
