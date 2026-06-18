import { useEffect, useState } from 'react'
import { parsePostgresDatabaseTabFromPathname } from '@/lib/postgres-database-routes'

export type PostgresSqlEditorActions = {
  canUndo: boolean
  canRedo: boolean
  canSave: boolean
  canFormat: boolean
  canRun: boolean
  canExplain: boolean
  canSelectNextTab: boolean
  canSelectPreviousTab: boolean
  undo: () => void
  redo: () => void
  save: () => void
  format: () => void
  run: () => void
  explain: () => void
  selectNextTab: () => void
  selectPreviousTab: () => void
}

let registeredActions: PostgresSqlEditorActions | null = null
const subscribers = new Set<() => void>()

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

export function isPostgresSqlWorkbenchFocused(): boolean {
  const activeElement = document.activeElement
  if (!activeElement || !(activeElement instanceof Element)) return false
  return activeElement.closest('[data-postgres-sql-workbench]') !== null
}
