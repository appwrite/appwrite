import {
  createContext,
  useContext,
  useMemo,
  type ReactNode,
} from 'react'
import { useDatabaseRowsFullscreen } from '@/hooks/use-database-rows-fullscreen'

type DatabaseTableRowsFullscreenContextValue = {
  rowsFullscreen: boolean
  toggleRowsFullscreen: () => void
}

const DatabaseTableRowsFullscreenContext =
  createContext<DatabaseTableRowsFullscreenContextValue | null>(null)

export function DatabaseTableRowsFullscreenProvider({
  enabled,
  children,
}: {
  enabled: boolean
  children: ReactNode
}) {
  const { rowsFullscreen, toggleRowsFullscreen } =
    useDatabaseRowsFullscreen(enabled)
  const value = useMemo(
    () => ({ rowsFullscreen, toggleRowsFullscreen }),
    [rowsFullscreen, toggleRowsFullscreen],
  )

  return (
    <DatabaseTableRowsFullscreenContext.Provider value={value}>
      {children}
    </DatabaseTableRowsFullscreenContext.Provider>
  )
}

export function useDatabaseTableRowsFullscreen() {
  const context = useContext(DatabaseTableRowsFullscreenContext)
  if (!context) {
    throw new Error(
      'useDatabaseTableRowsFullscreen must be used within DatabaseTableRowsFullscreenProvider',
    )
  }
  return context
}

export function useOptionalDatabaseTableRowsFullscreen() {
  return useContext(DatabaseTableRowsFullscreenContext)
}
