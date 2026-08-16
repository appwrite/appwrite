import { useEffect, useState } from 'react'
import { parseMysqlDatabaseTabFromPathname } from '@/lib/mysql-database-routes'

export type MysqlSqlEditorTabRef = {
  id: string
  title: string
}

export type MysqlSqlEditorActions = {
  canUndo: boolean
  canRedo: boolean
  canSave: boolean
  canFormat: boolean
  canRun: boolean
  canExplain: boolean
  canCreateTab: boolean
  canCloseTab: boolean
  canSelectNextTab: boolean
  canSelectPreviousTab: boolean
  canJumpToTab: boolean
  tabs: readonly MysqlSqlEditorTabRef[]
  undo: () => void
  redo: () => void
  save: () => void
  format: () => void
  run: () => void
  explain: () => void
  createTab: () => void
  closeTab: () => void
  selectNextTab: () => void
  selectPreviousTab: () => void
  /** 1-based index; 9 selects the last tab. */
  selectTabByIndex: (index: number) => void
  selectTab: (tabId: string) => void
  openJumpToTabPicker: () => void
}

let registeredActions: MysqlSqlEditorActions | null = null
const subscribers = new Set<() => void>()

let jumpToTabPickerHandler: (() => void) | null = null

export function registerMysqlSqlJumpToTabPicker(handler: (() => void) | null) {
  jumpToTabPickerHandler = handler
}

export function openMysqlSqlJumpToTabPicker() {
  jumpToTabPickerHandler?.()
}

export function registerMysqlSqlEditorActions(
  actions: MysqlSqlEditorActions | null,
) {
  registeredActions = actions
  subscribers.forEach((listener) => listener())
}

export function getMysqlSqlEditorActions(): MysqlSqlEditorActions | null {
  return registeredActions
}

export function subscribeMysqlSqlEditorActions(listener: () => void) {
  subscribers.add(listener)
  return () => {
    subscribers.delete(listener)
  }
}

export function useMysqlSqlEditorActions(): MysqlSqlEditorActions | null {
  const [, setVersion] = useState(0)

  useEffect(
    () =>
      subscribeMysqlSqlEditorActions(() => {
        setVersion((version) => version + 1)
      }),
    [],
  )

  return registeredActions
}

export function isMysqlSqlEditorPath(pathname: string): boolean {
  return parseMysqlDatabaseTabFromPathname(pathname) === 'sql'
}

/** True when keyboard focus is in the SQL Monaco editor (not tabs, toolbar, etc.). */
export function isMysqlSqlMonacoFocused(): boolean {
  if (typeof document === 'undefined') return false
  const workbench = document.querySelector('[data-mysql-sql-workbench]')
  return workbench?.querySelector('.monaco-editor:focus-within') != null
}

export function isMysqlSqlWorkbenchFocused(): boolean {
  return isMysqlSqlMonacoFocused()
}
