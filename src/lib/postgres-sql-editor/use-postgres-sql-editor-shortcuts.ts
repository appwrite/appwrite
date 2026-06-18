import { useCallback } from 'react'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import {
  POSTGRES_SQL_EXPLAIN_SHORTCUT_COMBOS,
  POSTGRES_SQL_FORMAT_SHORTCUT_COMBOS,
  POSTGRES_SQL_NEXT_TAB_SHORTCUT_COMBOS,
  POSTGRES_SQL_PREV_TAB_SHORTCUT_COMBOS,
  POSTGRES_SQL_RUN_SHORTCUT_COMBOS,
  POSTGRES_SQL_SAVE_SHORTCUT_COMBOS,
} from '@/lib/postgres-sql-editor-shortcuts'
import type { PostgresSqlEditorActions } from '@/lib/postgres-sql-editor-actions'
import { isPostgresSqlWorkbenchFocused } from '@/lib/postgres-sql-editor-actions'

const WORKBENCH_SHORTCUT_OPTIONS = {
  ignoreInputs: false,
  capture: true,
} as const

function useWorkbenchModShortcut(
  combos: readonly [string, string],
  handler: () => void,
  enabled: boolean,
) {
  const wrappedHandler = useCallback(
    (event: KeyboardEvent) => {
      if (!isPostgresSqlWorkbenchFocused()) return
      event.preventDefault()
      handler()
    },
    [handler],
  )

  useKeyboardShortcut(combos[0], wrappedHandler, {
    ...WORKBENCH_SHORTCUT_OPTIONS,
    enabled,
  })
  useKeyboardShortcut(combos[1], wrappedHandler, {
    ...WORKBENCH_SHORTCUT_OPTIONS,
    enabled,
  })
}

function useWorkbenchCtrlShortcut(
  combo: string,
  handler: () => void,
  enabled: boolean,
) {
  const wrappedHandler = useCallback(
    (event: KeyboardEvent) => {
      if (!isPostgresSqlWorkbenchFocused()) return
      event.preventDefault()
      handler()
    },
    [handler],
  )

  useKeyboardShortcut(combo, wrappedHandler, {
    ...WORKBENCH_SHORTCUT_OPTIONS,
    enabled,
  })
}

export function usePostgresSqlEditorShortcuts(
  actions: PostgresSqlEditorActions,
  enabled = true,
) {
  const run = useCallback(() => {
    if (actions.canRun) actions.run()
  }, [actions])

  const explain = useCallback(() => {
    if (actions.canExplain) actions.explain()
  }, [actions])

  const save = useCallback(() => {
    if (actions.canSave) actions.save()
  }, [actions])

  const format = useCallback(() => {
    if (actions.canFormat) actions.format()
  }, [actions])

  const selectNextTab = useCallback(() => {
    if (actions.canSelectNextTab) actions.selectNextTab()
  }, [actions])

  const selectPreviousTab = useCallback(() => {
    if (actions.canSelectPreviousTab) actions.selectPreviousTab()
  }, [actions])

  useWorkbenchModShortcut(POSTGRES_SQL_RUN_SHORTCUT_COMBOS, run, enabled)
  useWorkbenchModShortcut(POSTGRES_SQL_EXPLAIN_SHORTCUT_COMBOS, explain, enabled)
  useWorkbenchModShortcut(POSTGRES_SQL_SAVE_SHORTCUT_COMBOS, save, enabled)
  useWorkbenchCtrlShortcut(
    POSTGRES_SQL_NEXT_TAB_SHORTCUT_COMBOS[0],
    selectNextTab,
    enabled,
  )
  useWorkbenchCtrlShortcut(
    POSTGRES_SQL_PREV_TAB_SHORTCUT_COMBOS[0],
    selectPreviousTab,
    enabled,
  )

  const wrappedFormatHandler = useCallback(
    (event: KeyboardEvent) => {
      if (!isPostgresSqlWorkbenchFocused()) return
      event.preventDefault()
      format()
    },
    [format],
  )

  useKeyboardShortcut(POSTGRES_SQL_FORMAT_SHORTCUT_COMBOS[0], wrappedFormatHandler, {
    ...WORKBENCH_SHORTCUT_OPTIONS,
    enabled,
    stopPropagation: true,
  })
}
