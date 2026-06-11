import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'

const MAX_RECENT_QUERIES = 30

export type PostgresSidebarPanel = 'schemas' | 'queries'

export type PostgresRecentQuery = {
  id: string
  sql: string
  ranAt: number
}

type PostgresSidebarContextValue = {
  panel: PostgresSidebarPanel
  setPanel: (panel: PostgresSidebarPanel) => void
  recentQueries: PostgresRecentQuery[]
  addRecentQuery: (sql: string) => void
  selectedQueryId: string | null
  selectQuery: (query: PostgresRecentQuery) => void
  registerSqlLoader: (loader: (sql: string) => void) => void
}

const PostgresSidebarContext = createContext<PostgresSidebarContextValue | null>(
  null,
)

function queryPreview(sql: string): string {
  const line = sql.trim().split('\n')[0]?.trim() ?? sql.trim()
  if (line.length <= 72) return line
  return `${line.slice(0, 69)}...`
}

export function queryPreviewLabel(sql: string): string {
  return queryPreview(sql)
}

export function PostgresSidebarProvider({ children }: { children: ReactNode }) {
  const [panel, setPanel] = useState<PostgresSidebarPanel>('schemas')
  const [recentQueries, setRecentQueries] = useState<PostgresRecentQuery[]>([])
  const [selectedQueryId, setSelectedQueryId] = useState<string | null>(null)
  const sqlLoaderRef = useRef<((sql: string) => void) | null>(null)

  const registerSqlLoader = useCallback((loader: (sql: string) => void) => {
    sqlLoaderRef.current = loader
  }, [])

  const addRecentQuery = useCallback((sql: string) => {
    const trimmed = sql.trim()
    if (!trimmed) return

    setRecentQueries((prev) => {
      const existing = prev.find((entry) => entry.sql === trimmed)
      const nextEntry: PostgresRecentQuery = {
        id: existing?.id ?? crypto.randomUUID(),
        sql: trimmed,
        ranAt: Date.now(),
      }
      const without = prev.filter((entry) => entry.sql !== trimmed)
      return [nextEntry, ...without].slice(0, MAX_RECENT_QUERIES)
    })
  }, [])

  const selectQuery = useCallback((query: PostgresRecentQuery) => {
    setSelectedQueryId(query.id)
    sqlLoaderRef.current?.(query.sql)
  }, [])

  const value = useMemo(
    () => ({
      panel,
      setPanel,
      recentQueries,
      addRecentQuery,
      selectedQueryId,
      selectQuery,
      registerSqlLoader,
    }),
    [
      panel,
      recentQueries,
      addRecentQuery,
      selectedQueryId,
      selectQuery,
      registerSqlLoader,
    ],
  )

  return (
    <PostgresSidebarContext.Provider value={value}>
      {children}
    </PostgresSidebarContext.Provider>
  )
}

export function usePostgresSidebar() {
  const context = useContext(PostgresSidebarContext)
  if (!context) {
    throw new Error('usePostgresSidebar must be used within PostgresSidebarProvider')
  }
  return context
}
