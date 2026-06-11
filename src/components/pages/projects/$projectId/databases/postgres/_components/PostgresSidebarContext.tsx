import type { Models } from '@appwrite.io/console'
import type { SavedPostgresQuery } from '@/lib/user-prefs-keys'
import { useParams } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  usePostgresSavedQueryScope,
  type PostgresSavedQueryLevel,
} from '@/lib/react-query/hooks/postgres-databases'
import { useProject } from '@/lib/react-query/hooks/projects'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { parsePostgresTableId } from '@/lib/postgres-database-routes'
import { buildPostgresSelectSql } from '@/lib/postgres-sql'
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
const DEFAULT_SQL = 'SELECT NOW() AS current_time;'

export type PostgresSidebarPanel = 'schemas' | 'queries' | 'history'

export type PostgresRecentQuery = {
  id: string
  sql: string
  ranAt: number
}

export type SqlEditorTab = {
  id: string
  title: string
  sql: string
  tableId?: string
  result: Models.DedicatedDatabaseExecution | null
  error: unknown
}

type PostgresSidebarContextValue = {
  panel: PostgresSidebarPanel
  setPanel: (panel: PostgresSidebarPanel) => void
  recentQueries: PostgresRecentQuery[]
  addRecentQuery: (sql: string) => void
  clearRecentQueries: () => void
  selectedQueryKey: string | null
  savedQueryLevel: PostgresSavedQueryLevel
  setSavedQueryLevel: (level: PostgresSavedQueryLevel) => void
  selectRecentQuery: (query: PostgresRecentQuery) => void
  selectSavedQuery: (
    level: PostgresSavedQueryLevel,
    query: SavedPostgresQuery,
  ) => void
  tabs: SqlEditorTab[]
  activeTabId: string
  activeTab: SqlEditorTab
  openTableTab: (tableId: string) => void
  openQueryTab: (sql: string) => void
  createTab: () => void
  closeTab: (tabId: string) => void
  reorderTabs: (activeId: string, overId: string) => void
  setActiveTabId: (tabId: string) => void
  updateActiveTabSql: (sql: string) => void
  setActiveTabResult: (
    result: Models.DedicatedDatabaseExecution | null,
    error?: unknown,
  ) => void
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

export function postgresQuerySelectionKey(
  kind: 'recent' | PostgresSavedQueryLevel,
  id: string,
): string {
  return `${kind}:${id}`
}

function createBlankTab(existingCount: number): SqlEditorTab {
  return {
    id: crypto.randomUUID(),
    title: `Query ${existingCount + 1}`,
    sql: DEFAULT_SQL,
    result: null,
    error: null,
  }
}

function createTableTab(tableId: string): SqlEditorTab {
  const { schema, table } = parsePostgresTableId(tableId)
  return {
    id: crypto.randomUUID(),
    title: table,
    sql: buildPostgresSelectSql(schema, table, ROWS_DEFAULT_PAGE_SIZE, 0),
    tableId,
    result: null,
    error: null,
  }
}

function createInitialTabState() {
  const initialTab = createBlankTab(0)
  return { tabs: [initialTab], activeTabId: initialTab.id }
}

type PostgresSidebarProviderProps = {
  databaseId: string
  children: ReactNode
}

export function PostgresSidebarProvider({
  databaseId,
  children,
}: PostgresSidebarProviderProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { account } = useAuth()
  const { project } = useProject(projectId)
  const teamId = project?.teamId ?? null
  const { savedQueryLevel, setSavedQueryLevel } = usePostgresSavedQueryScope(
    databaseId,
    account,
    teamId,
  )

  const [panel, setPanel] = useState<PostgresSidebarPanel>('schemas')
  const [recentQueries, setRecentQueries] = useState<PostgresRecentQuery[]>([])
  const [selectedQueryKey, setSelectedQueryKey] = useState<string | null>(null)
  const initialTabStateRef = useRef(createInitialTabState())
  const [tabs, setTabs] = useState(initialTabStateRef.current.tabs)
  const [activeTabId, setActiveTabId] = useState(
    initialTabStateRef.current.activeTabId,
  )

  const activeTab = useMemo(
    () => tabs.find((tab) => tab.id === activeTabId) ?? tabs[0],
    [activeTabId, tabs],
  )

  const updateActiveTabSql = useCallback((sql: string) => {
    setTabs((prev) =>
      prev.map((tab) =>
        tab.id === activeTabId ? { ...tab, sql, result: null, error: null } : tab,
      ),
    )
  }, [activeTabId])

  const setActiveTabResult = useCallback(
    (
      result: Models.DedicatedDatabaseExecution | null,
      error: unknown = null,
    ) => {
      setTabs((prev) =>
        prev.map((tab) =>
          tab.id === activeTabId ? { ...tab, result, error } : tab,
        ),
      )
    },
    [activeTabId],
  )

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

  const clearRecentQueries = useCallback(() => {
    setRecentQueries([])
    setSelectedQueryKey((key) =>
      key?.startsWith('recent:') ? null : key,
    )
  }, [])

  const openQueryTab = useCallback((sql: string) => {
    const trimmed = sql.trim()
    if (!trimmed) return

    setTabs((prev) => {
      const existing = prev.find((tab) => tab.sql === trimmed)
      if (existing) {
        setActiveTabId(existing.id)
        return prev
      }
      const newTab: SqlEditorTab = {
        id: crypto.randomUUID(),
        title: queryPreviewLabel(trimmed),
        sql: trimmed,
        result: null,
        error: null,
      }
      setActiveTabId(newTab.id)
      return [...prev, newTab]
    })
  }, [])

  const selectRecentQuery = useCallback(
    (query: PostgresRecentQuery) => {
      setSelectedQueryKey(postgresQuerySelectionKey('recent', query.id))
      openQueryTab(query.sql)
    },
    [openQueryTab],
  )

  const selectSavedQuery = useCallback(
    (level: PostgresSavedQueryLevel, query: SavedPostgresQuery) => {
      setSelectedQueryKey(postgresQuerySelectionKey(level, query.id))
      openQueryTab(query.sql)
    },
    [openQueryTab],
  )

  const openTableTab = useCallback((tableId: string) => {
    setTabs((prev) => {
      const existing = prev.find((tab) => tab.tableId === tableId)
      if (existing) {
        setActiveTabId(existing.id)
        return prev
      }
      const newTab = createTableTab(tableId)
      setActiveTabId(newTab.id)
      return [...prev, newTab]
    })
  }, [])

  const createTab = useCallback(() => {
    let newTabId = ''
    setTabs((prev) => {
      const newTab = createBlankTab(prev.length)
      newTabId = newTab.id
      return [...prev, newTab]
    })
    setActiveTabId(newTabId)
    setSelectedQueryKey(null)
  }, [])

  const closeTab = useCallback((tabId: string) => {
    setTabs((prev) => {
      if (prev.length <= 1) return prev
      const index = prev.findIndex((tab) => tab.id === tabId)
      if (index < 0) return prev

      const next = prev.filter((tab) => tab.id !== tabId)
      if (tabId === activeTabId) {
        const nextIndex = Math.min(index, next.length - 1)
        setActiveTabId(next[nextIndex]?.id ?? next[0].id)
      }
      return next
    })
  }, [activeTabId])

  const reorderTabs = useCallback((activeId: string, overId: string) => {
    setTabs((prev) => {
      const oldIndex = prev.findIndex((tab) => tab.id === activeId)
      const newIndex = prev.findIndex((tab) => tab.id === overId)
      if (oldIndex < 0 || newIndex < 0 || oldIndex === newIndex) return prev

      const next = [...prev]
      const [moved] = next.splice(oldIndex, 1)
      next.splice(newIndex, 0, moved)
      return next
    })
  }, [])

  const value = useMemo(
    () => ({
      panel,
      setPanel,
      recentQueries,
      addRecentQuery,
      clearRecentQueries,
      selectedQueryKey,
      savedQueryLevel,
      setSavedQueryLevel,
      selectRecentQuery,
      selectSavedQuery,
      tabs,
      activeTabId,
      activeTab,
      openTableTab,
      openQueryTab,
      createTab,
      closeTab,
      reorderTabs,
      setActiveTabId,
      updateActiveTabSql,
      setActiveTabResult,
    }),
    [
      panel,
      recentQueries,
      addRecentQuery,
      clearRecentQueries,
      selectedQueryKey,
      savedQueryLevel,
      setSavedQueryLevel,
      selectRecentQuery,
      selectSavedQuery,
      tabs,
      activeTabId,
      activeTab,
      openTableTab,
      openQueryTab,
      createTab,
      closeTab,
      reorderTabs,
      updateActiveTabSql,
      setActiveTabResult,
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
