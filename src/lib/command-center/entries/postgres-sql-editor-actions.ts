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
  getPostgresSqlEditorActions,
  isPostgresSqlEditorPath,
} from '@/lib/postgres-sql-editor-actions'
import {
  POSTGRES_SQL_CLOSE_TAB_SHORTCUT_RAW,
  POSTGRES_SQL_EXPLAIN_SHORTCUT_RAW,
  POSTGRES_SQL_FORMAT_SHORTCUT_RAW,
  POSTGRES_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW,
  POSTGRES_SQL_NEW_TAB_SHORTCUT_RAW,
  POSTGRES_SQL_NEXT_TAB_SHORTCUT_RAW,
  POSTGRES_SQL_PREV_TAB_SHORTCUT_RAW,
  POSTGRES_SQL_REDO_SHORTCUT_RAW,
  POSTGRES_SQL_RUN_SHORTCUT_RAW,
  POSTGRES_SQL_SAVE_SHORTCUT_RAW,
  POSTGRES_SQL_UNDO_SHORTCUT_RAW,
} from '@/lib/postgres-sql-editor-shortcuts'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

function sqlEditorActionsAvailable(pathname: string): boolean {
  return (
    isPostgresSqlEditorPath(pathname) && getPostgresSqlEditorActions() !== null
  )
}

function disabledReasonWhen(
  can: boolean | undefined,
  reason: string,
): string | undefined {
  return can ? undefined : reason
}

const POSTGRES_SQL_EDITOR_ACTIONS: CommandEntry[] = [
  {
    id: 'postgres.sql.run',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Run',
    description: 'Execute the current SQL in the editor',
    icon: Play,
    shortcut: POSTGRES_SQL_RUN_SHORTCUT_RAW,
    keywords: ['sql', 'execute', 'query', 'run', 'postgres'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canRun,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canRun,
        'Write SQL before running a query.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.run()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.explain',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Explain',
    description: 'Show the query execution plan',
    icon: ListTree,
    shortcut: POSTGRES_SQL_EXPLAIN_SHORTCUT_RAW,
    keywords: ['sql', 'explain', 'plan', 'postgres', 'analyze'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canExplain,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canExplain,
        'Write SQL before explaining a query.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.explain()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.save',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Save query',
    description: 'Save the current SQL as a personal or team query',
    icon: Bookmark,
    shortcut: POSTGRES_SQL_SAVE_SHORTCUT_RAW,
    keywords: ['sql', 'save', 'bookmark', 'query', 'postgres'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canSave,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canSave,
        'Write SQL before saving a query.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.save()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.format',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Format SQL',
    description: 'Format the current SQL in the editor',
    icon: Braces,
    shortcut: POSTGRES_SQL_FORMAT_SHORTCUT_RAW,
    keywords: ['sql', 'format', 'prettify', 'postgres'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canFormat,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canFormat,
        'Write SQL before formatting.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.format()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.undo',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Undo',
    description: 'Undo the last SQL editor change',
    icon: Undo2,
    shortcut: POSTGRES_SQL_UNDO_SHORTCUT_RAW,
    keywords: ['sql', 'undo', 'editor', 'postgres'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canUndo,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canUndo,
        'Nothing to undo.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.undo()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.redo',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Redo',
    description: 'Redo the last undone SQL editor change',
    icon: Redo2,
    shortcut: POSTGRES_SQL_REDO_SHORTCUT_RAW,
    keywords: ['sql', 'redo', 'editor', 'postgres'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canRedo,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canRedo,
        'Nothing to redo.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.redo()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.next-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Next query tab',
    description: 'Switch to the next SQL editor tab',
    icon: ChevronRight,
    shortcut: POSTGRES_SQL_NEXT_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'next', 'postgres', 'editor'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canSelectNextTab,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canSelectNextTab,
        'Open another query tab to switch tabs.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.selectNextTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.prev-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Previous query tab',
    description: 'Switch to the previous SQL editor tab',
    icon: ChevronLeft,
    shortcut: POSTGRES_SQL_PREV_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'previous', 'postgres', 'editor'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canSelectPreviousTab,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canSelectPreviousTab,
        'Open another query tab to switch tabs.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.selectPreviousTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.new-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'New query tab',
    description: 'Open a new SQL editor tab',
    icon: Plus,
    shortcut: POSTGRES_SQL_NEW_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'new', 'postgres', 'editor', 'query'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canCreateTab,
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.createTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.close-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Close query tab',
    description: 'Close the current SQL editor tab',
    icon: X,
    shortcut: POSTGRES_SQL_CLOSE_TAB_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'close', 'postgres', 'editor', 'query'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canCloseTab,
    disabledReason: () =>
      disabledReasonWhen(
        getPostgresSqlEditorActions()?.canCloseTab,
        'Keep at least one query tab open.',
      ),
    perform: (ctx) => {
      getPostgresSqlEditorActions()?.closeTab()
      ctx.closeCommandCenter()
    },
  },
  {
    id: 'postgres.sql.jump-tab',
    scopes: ['project'],
    kind: 'action',
    group: 'SQL editor',
    label: 'Go to query tab',
    description: 'Pick a SQL editor tab to open',
    icon: PanelTop,
    shortcut: POSTGRES_SQL_JUMP_TAB_PICKER_SHORTCUT_RAW,
    keywords: ['sql', 'tab', 'jump', 'goto', 'postgres', 'editor', 'query'],
    available: (ctx) => sqlEditorActionsAvailable(ctx.pathname),
    disabled: () => !getPostgresSqlEditorActions()?.canJumpToTab,
    perform: () => {
      getPostgresSqlEditorActions()?.openJumpToTabPicker()
    },
  },
]

registerCommands(POSTGRES_SQL_EDITOR_ACTIONS)
