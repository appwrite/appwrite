import { useEffect, useState } from 'react'
import { parsePostgresDatabaseTabFromPathname } from '@/lib/postgres-database-routes'

export type PostgresSqlEditorTabRef = {
  id: string
  title: string
}

export type PostgresSqlEditorActions = {
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
  tabs: readonly PostgresSqlEditorTabRef[]
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

let registeredActions: PostgresSqlEditorActions | null = null
const subscribers = new Set<() => void>()

let jumpToTabPickerHandler: (() => void) | null = null

export function registerPostgresSqlJumpToTabPicker(handler: (() => void) | null) {
  jumpToTabPickerHandler = handler
}

export function openPostgresSqlJumpToTabPicker() {
  jumpToTabPickerHandler?.()
}

export function registerPostgresSqlEditorActions(
  actions: PostgresSqlEditorActions | null,
) {
  registeredActions = actions
  subscribers.forEach((listener) => listener())
}

export function getPostgresSqlEditorActions(): PostgresSqlEditorActions | null {
  return registeredActions
}

export function subscribePostgresSqlEditorActions(listener: () => void) {
  subscribers.add(listener)
  return () => {
    subscribers.delete(listener)
  }
}

export function usePostgresSqlEditorActions(): PostgresSqlEditorActions | null {
  const [, setVersion] = useState(0)

  useEffect(
    () =>
      subscribePostgresSqlEditorActions(() => {
        setVersion((version) => version + 1)
      }),
    [],
  )

  return registeredActions
}

export function isPostgresSqlEditorPath(pathname: string): boolean {
  return parsePostgresDatabaseTabFromPathname(pathname) === 'sql'
}

/** True when keyboard focus is in the SQL Monaco editor (not tabs, toolbar, etc.). */
export function isPostgresSqlMonacoFocused(): boolean {
  if (typeof document === 'undefined') return false
  const workbench = document.querySelector('[data-postgres-sql-workbench]')
  return workbench?.querySelector('.monaco-editor:focus-within') != null
}

export function isPostgresSqlWorkbenchFocused(): boolean {
  return isPostgresSqlMonacoFocused()
}
