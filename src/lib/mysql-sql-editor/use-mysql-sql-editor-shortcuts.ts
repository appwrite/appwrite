import { useEffect, useCallback } from 'react'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import {
  MYSQL_SQL_CLOSE_TAB_SHORTCUT_COMBOS,
  MYSQL_SQL_EXPLAIN_SHORTCUT_COMBOS,
  MYSQL_SQL_FORMAT_SHORTCUT_COMBOS,
  MYSQL_SQL_JUMP_TAB_PICKER_SHORTCUT_COMBOS,
  MYSQL_SQL_NEW_TAB_SHORTCUT_COMBOS,
  MYSQL_SQL_NEXT_TAB_SHORTCUT_COMBOS,
  MYSQL_SQL_PREV_TAB_SHORTCUT_COMBOS,
  MYSQL_SQL_RUN_SHORTCUT_COMBOS,
  MYSQL_SQL_SAVE_SHORTCUT_COMBOS,
} from '@/lib/mysql-sql-editor-shortcuts'
import type { MysqlSqlEditorActions } from '@/lib/mysql-sql-editor-actions'
import { isMysqlSqlMonacoFocused } from '@/lib/mysql-sql-editor-actions'

const WORKBENCH_SHORTCUT_OPTIONS = {
  ignoreInputs: false,
  capture: true,
  preventDefault: false,
} as const

function useWorkbenchModShortcut(
  combos: readonly [string, string],
  handler: () => void,
  enabled: boolean,
) {
  const wrappedHandler = useCallback(
    (event: KeyboardEvent) => {
      if (!isMysqlSqlMonacoFocused()) return
      event.preventDefault()
      event.stopPropagation()
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
      if (!isMysqlSqlMonacoFocused()) return
      event.preventDefault()
      event.stopPropagation()
      handler()
    },
    [handler],
  )

  useKeyboardShortcut(combo, wrappedHandler, {
    ...WORKBENCH_SHORTCUT_OPTIONS,
    enabled,
  })
}

function useWorkbenchModDigitShortcuts(
  selectTabByIndex: (index: number) => void,
  enabled: boolean,
) {
  useEffect(() => {
    if (!enabled) return

    const handleKeyDown = (event: KeyboardEvent) => {
      if (!isMysqlSqlMonacoFocused()) return
      if (!event.metaKey && !event.ctrlKey) return
      if (event.altKey || event.shiftKey) return

      const digit = event.key >= '1' && event.key <= '9' ? Number(event.key) : null
      if (!digit) return

      event.preventDefault()
      event.stopPropagation()
      selectTabByIndex(digit)
    }

    window.addEventListener('keydown', handleKeyDown, { capture: true })
    return () => window.removeEventListener('keydown', handleKeyDown, { capture: true })
  }, [enabled, selectTabByIndex])
}

export function useMysqlSqlEditorShortcuts(
  actions: MysqlSqlEditorActions,
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

  const createTab = useCallback(() => {
    if (actions.canCreateTab) actions.createTab()
  }, [actions])

  const closeTab = useCallback(() => {
    if (actions.canCloseTab) actions.closeTab()
  }, [actions])

  const openJumpToTabPicker = useCallback(() => {
    if (actions.canJumpToTab) actions.openJumpToTabPicker()
  }, [actions])

  const selectTabByIndex = useCallback(
    (index: number) => {
      if (actions.canJumpToTab) actions.selectTabByIndex(index)
    },
    [actions],
  )

  useWorkbenchModShortcut(MYSQL_SQL_RUN_SHORTCUT_COMBOS, run, enabled)
  useWorkbenchModShortcut(MYSQL_SQL_EXPLAIN_SHORTCUT_COMBOS, explain, enabled)
  useWorkbenchModShortcut(MYSQL_SQL_SAVE_SHORTCUT_COMBOS, save, enabled)
  useWorkbenchModShortcut(MYSQL_SQL_NEW_TAB_SHORTCUT_COMBOS, createTab, enabled)
  useWorkbenchModShortcut(
    MYSQL_SQL_CLOSE_TAB_SHORTCUT_COMBOS,
    closeTab,
    enabled,
  )
  useWorkbenchModShortcut(
    MYSQL_SQL_JUMP_TAB_PICKER_SHORTCUT_COMBOS,
    openJumpToTabPicker,
    enabled,
  )
  useWorkbenchCtrlShortcut(
    MYSQL_SQL_NEXT_TAB_SHORTCUT_COMBOS[0],
    selectNextTab,
    enabled,
  )
  useWorkbenchCtrlShortcut(
    MYSQL_SQL_PREV_TAB_SHORTCUT_COMBOS[0],
    selectPreviousTab,
    enabled,
  )

  useWorkbenchModDigitShortcuts(selectTabByIndex, enabled)

  const wrappedFormatHandler = useCallback(
    (event: KeyboardEvent) => {
      if (!isMysqlSqlMonacoFocused()) return
      event.preventDefault()
      event.stopPropagation()
      format()
    },
    [format],
  )

  useKeyboardShortcut(MYSQL_SQL_FORMAT_SHORTCUT_COMBOS[0], wrappedFormatHandler, {
    ...WORKBENCH_SHORTCUT_OPTIONS,
    enabled,
  })
}
