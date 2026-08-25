/** Display metadata for Mysql SQL editor shortcuts. */
export type MysqlSqlEditorShortcutRef = {
  id: string
  description: string
  /** Shortcut string in the same format as command-center entries (e.g. `mod+enter`). */
  raw: string
}

export const MYSQL_SQL_RUN_SHORTCUT_RAW = 'mod+enter'

export const MYSQL_SQL_RUN_SHORTCUT_COMBOS = [
  'meta+enter',
  'control+enter',
] as const

export const MYSQL_SQL_EXPLAIN_SHORTCUT_RAW = 'mod+shift+e'

export const MYSQL_SQL_EXPLAIN_SHORTCUT_COMBOS = [
  'meta+shift+e',
  'control+shift+e',
] as const

export const MYSQL_SQL_SAVE_SHORTCUT_RAW = 'mod+s'

export const MYSQL_SQL_SAVE_SHORTCUT_COMBOS = [
  'meta+s',
  'control+s',
] as const

/** Format document in the Mysql SQL editor (Shift+Alt+F). */
export const MYSQL_SQL_FORMAT_SHORTCUT_RAW = 'shift+alt+f'

export const MYSQL_SQL_FORMAT_SHORTCUT_COMBOS = [
  'shift+alt+f',
] as const

export const MYSQL_SQL_UNDO_SHORTCUT_RAW = 'mod+z'

export const MYSQL_SQL_REDO_SHORTCUT_RAW = 'mod+shift+z'

/** Control (not Command) so Mac app switching (⌘Tab) stays untouched. */
export const MYSQL_SQL_NEXT_TAB_SHORTCUT_RAW = 'ctrl+tab'

export const MYSQL_SQL_NEXT_TAB_SHORTCUT_COMBOS = [
  'control+tab',
] as const

export const MYSQL_SQL_PREV_TAB_SHORTCUT_RAW = 'ctrl+shift+tab'

export const MYSQL_SQL_PREV_TAB_SHORTCUT_COMBOS = [
  'control+shift+tab',
] as const

export const MYSQL_SQL_NEW_TAB_SHORTCUT_RAW = 'mod+t'

export const MYSQL_SQL_NEW_TAB_SHORTCUT_COMBOS = [
  'meta+t',
  'control+t',
] as const

export const MYSQL_SQL_CLOSE_TAB_SHORTCUT_RAW = 'mod+w'

export const MYSQL_SQL_CLOSE_TAB_SHORTCUT_COMBOS = [
  'meta+w',
  'control+w',
] as const

export const MYSQL_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW = 'mod+shift+p'

export const MYSQL_SQL_JUMP_TAB_PICKER_SHORTCUT_COMBOS = [
  'meta+shift+p',
  'control+shift+p',
] as const

export const MYSQL_SQL_JUMP_TAB_DIGIT_SHORTCUTS: readonly MysqlSqlEditorShortcutRef[] =
  Array.from({ length: 8 }, (_, index) => ({
    id: `mysql-sql.jump-tab-${index + 1}`,
    description: `Jump to tab ${index + 1}`,
    raw: `mod+${index + 1}`,
  }))

export const MYSQL_SQL_EDITOR_SHORTCUTS: readonly MysqlSqlEditorShortcutRef[] =
  [
    {
      id: 'mysql-sql.run',
      description: 'Run',
      raw: MYSQL_SQL_RUN_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.explain',
      description: 'Explain',
      raw: MYSQL_SQL_EXPLAIN_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.save',
      description: 'Save query',
      raw: MYSQL_SQL_SAVE_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.format',
      description: 'Format SQL',
      raw: MYSQL_SQL_FORMAT_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.undo',
      description: 'Undo',
      raw: MYSQL_SQL_UNDO_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.redo',
      description: 'Redo',
      raw: MYSQL_SQL_REDO_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.next-tab',
      description: 'Next query tab',
      raw: MYSQL_SQL_NEXT_TAB_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.prev-tab',
      description: 'Previous query tab',
      raw: MYSQL_SQL_PREV_TAB_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.new-tab',
      description: 'New query tab',
      raw: MYSQL_SQL_NEW_TAB_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.close-tab',
      description: 'Close query tab',
      raw: MYSQL_SQL_CLOSE_TAB_SHORTCUT_RAW,
    },
    {
      id: 'mysql-sql.jump-tab-picker',
      description: 'Go to query tab',
      raw: MYSQL_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW,
    },
    ...MYSQL_SQL_JUMP_TAB_DIGIT_SHORTCUTS,
    {
      id: 'mysql-sql.jump-tab-last',
      description: 'Jump to last tab',
      raw: 'mod+9',
    },
  ]
