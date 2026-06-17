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
  ]
