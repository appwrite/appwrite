import {
  Bookmark,
  Braces,
  ChevronLeft,
  ChevronRight,
  ListTree,
  PanelTop,
  Play,
  Plus,
  Redo2,
  Undo2,
  X,
} from 'lucide-react'
import {
  getMysqlSqlEditorActions,
  isMysqlSqlEditorPath,
} from '@/lib/mysql-sql-editor-actions'
import {
  MYSQL_SQL_CLOSE_TAB_SHORTCUT_RAW,
  MYSQL_SQL_EXPLAIN_SHORTCUT_RAW,
  MYSQL_SQL_FORMAT_SHORTCUT_RAW,
  MYSQL_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW,
  MYSQL_SQL_NEW_TAB_SHORTCUT_RAW,
  MYSQL_SQL_NEXT_TAB_SHORTCUT_RAW,
  MYSQL_SQL_PREV_TAB_SHORTCUT_RAW,
  MYSQL_SQL_REDO_SHORTCUT_RAW,
  MYSQL_SQL_RUN_SHORTCUT_RAW,
  MYSQL_SQL_SAVE_SHORTCUT_RAW,
  MYSQL_SQL_UNDO_SHORTCUT_RAW,
} from '@/lib/mysql-sql-editor-shortcuts'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

function sqlEditorActionsAvailable(pathname: string): boolean {
  return (
    isMysqlSqlEditorPath(pathname) && getMysqlSqlEditorActions() !== null
  )
}

function disabledReasonWhen(
  can: boolean | undefined,
  reason: string,
): string | undefined {
  return can ? undefined : reason
}

const MYSQL_SQL_EDITOR_ACTIONS: CommandEntry[] = [
  {
    id: 'mysql.sql.run',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Run',
    description: 'Execute the current SQL in the editor',
    icon: Play,
    shortcut: MYSQL_SQL_RUN_SHORTCUT_RAW,
    keywords: ['sql', 'execute', 'query', 'run', 'mysql'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canRun,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canRun,
        'Write SQL before running a query.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.run()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.explain',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Explain',
    description: 'Show the query execution plan',
    icon: ListTree,
    shortcut: MYSQL_SQL_EXPLAIN_SHORTCUT_RAW,
    keywords: ['sql', 'explain', 'plan', 'mysql', 'analyze'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canExplain,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canExplain,
        'Write SQL before explaining a query.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.explain()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.save',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Save query',
    description: 'Save the current SQL as a personal or team query',
    icon: Bookmark,
    shortcut: MYSQL_SQL_SAVE_SHORTCUT_RAW,
    keywords: ['sql', 'save', 'bookmark', 'query', 'mysql'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canSave,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canSave,
        'Write SQL before saving a query.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.save()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.format',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Format SQL',
    description: 'Format the current SQL in the editor',
    icon: Braces,
    shortcut: MYSQL_SQL_FORMAT_SHORTCUT_RAW,
    keywords: ['sql', 'format', 'prettify', 'mysql'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canFormat,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canFormat,
        'Write SQL before formatting.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.format()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.undo',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Undo',
    description: 'Undo the last SQL editor change',
    icon: Undo2,
    shortcut: MYSQL_SQL_UNDO_SHORTCUT_RAW,
    keywords: ['sql', 'undo', 'editor', 'mysql'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canUndo,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canUndo,
        'Nothing to undo.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.undo()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.redo',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Redo',
    description: 'Redo the last undone SQL editor change',
    icon: Redo2,
    shortcut: MYSQL_SQL_REDO_SHORTCUT_RAW,
    keywords: ['sql', 'redo', 'editor', 'mysql'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canRedo,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canRedo,
        'Nothing to redo.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.redo()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.next-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Next query tab',
    description: 'Switch to the next SQL editor tab',
    icon: ChevronRight,
    shortcut: MYSQL_SQL_NEXT_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'next', 'mysql', 'editor'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canSelectNextTab,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canSelectNextTab,
        'Open another query tab to switch tabs.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.selectNextTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.prev-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Previous query tab',
    description: 'Switch to the previous SQL editor tab',
    icon: ChevronLeft,
    shortcut: MYSQL_SQL_PREV_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'previous', 'mysql', 'editor'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canSelectPreviousTab,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canSelectPreviousTab,
        'Open another query tab to switch tabs.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.selectPreviousTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.new-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'New query tab',
    description: 'Open a new SQL editor tab',
    icon: Plus,
    shortcut: MYSQL_SQL_NEW_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'new', 'mysql', 'editor', 'query'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canCreateTab,
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.createTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.close-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Close query tab',
    description: 'Close the current SQL editor tab',
    icon: X,
    shortcut: MYSQL_SQL_CLOSE_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'close', 'mysql', 'editor', 'query'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canCloseTab,
    disabledReason: () =>
      disabledReasonWhen(
        getMysqlSqlEditorActions()?.canCloseTab,
        'Keep at least one query tab open.',
      ),
    perform: (ctx) => {
      getMysqlSqlEditorActions()?.closeTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'mysql.sql.jump-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Go to query tab',
    description: 'Pick a SQL editor tab to open',
    icon: PanelTop,
    shortcut: MYSQL_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'jump', 'goto', 'mysql', 'editor', 'query'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getMysqlSqlEditorActions()?.canJumpToTab,
    perform: () => {
      getMysqlSqlEditorActions()?.openJumpToTabPicker()
    },
  },
]

registerCommands(MYSQL_SQL_EDITOR_ACTIONS)
