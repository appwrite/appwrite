import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import type { DedicatedDatabaseQueryExplanation } from '@/lib/databases/dedicated-engine'
import {
  MAX_MYSQL_QUERY_HISTORY_ENTRIES,
  MAX_MYSQL_SQL_EDITOR_TAB_TITLE_LENGTH,
  type PersistedMysqlSqlEditorState,
  type MysqlQueryHistoryEntry,
  type SavedMysqlQuery,
} from '@/lib/user-prefs-keys'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  normalizeMysqlTableRouteId,
  parseMysqlTableId,
  mysqlNav,
  mysqlTableRows,
} from '@/lib/mysql-database-routes'
import { buildMysqlSelectSql } from '@/lib/mysql-sql'
import {
  useMysqlQueryHistory,
  useMysqlSavedQueryScope,
  useMysqlSidebarPanel,
  useMysqlSidebarSchemas,
  useMysqlSelectedSchema,
  useMysqlSqlEditorPersistence,
  type MysqlSavedQueryLevel,
} from '@/lib/react-query/hooks/mysql-databases'
import { useProject } from '@/lib/react-query/hooks/projects'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { flushSync } from 'react-dom'

const DEFAULT_SQL = 'SELECT NOW() AS now;'

export type MysqlSidebarPanel =
  import('@/lib/user-prefs-keys').MysqlSidebarPanelPreference

export type MysqlRecentQuery = MysqlQueryHistoryEntry

export type SqlEditorTab = {
  id: string
  title: string
  sql: string
  tableId?: string
  result: Models.DedicatedDatabaseExecution | null
  explainResult: DedicatedDatabaseQueryExplanation | null
  error: unknown
  resultKind?: 'query' | 'explain'
}

type MysqlSidebarContextValue = {
  panel: MysqlSidebarPanel
  setPanel: (panel: MysqlSidebarPanel) => void
  selectedSchema: string | null
  setSelectedSchema: (schema: string) => void
  recentQueries: MysqlRecentQuery[]
  addRecentQuery: (sql: string) => void
  clearRecentQueries: () => void
  selectedQueryKey: string | null
  savedQueryLevel: MysqlSavedQueryLevel
  setSavedQueryLevel: (level: MysqlSavedQueryLevel) => void
  selectRecentQuery: (query: MysqlRecentQuery) => void
  selectSavedQuery: (
    level: MysqlSavedQueryLevel,
    query: SavedMysqlQuery,
  ) => void
  tabs: SqlEditorTab[]
  activeTabId: string
  activeTab: SqlEditorTab
  openTableInEditor: (tableId: string) => void
  openTableInSqlEditor: (tableId: string) => void
  focusTableRouteTab: (tableId: string) => void
  openQueryTab: (sql: string) => void
  createTab: () => void
  closeTab: (tabId: string) => void
  closeOtherTabs: (tabId: string) => void
  duplicateQueryTab: (sql: string) => void
  reorderTabs: (activeId: string, overId: string) => void
  renameTab: (tabId: string, title: string) => void
  setActiveTabId: (tabId: string) => void
  updateActiveTabSql: (sql: string) => void
  setActiveTabResult: (
    result: Models.DedicatedDatabaseExecution | null,
    error?: unknown,
    resultKind?: 'query' | 'explain',
    explainResult?: DedicatedDatabaseQueryExplanation | null,
  ) => void
}

const MysqlSidebarContext = createContext<MysqlSidebarContextValue | null>(
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

export function mysqlQuerySelectionKey(
  kind: 'recent' | MysqlSavedQueryLevel,
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
    explainResult: null,
    error: null,
  }
}

function createTableTab(tableId: string): SqlEditorTab {
  const { schema, table } = parseMysqlTableId(tableId)
  return {
    id: crypto.randomUUID(),
    title: table,
    sql: buildMysqlSelectSql(schema, table, ROWS_DEFAULT_PAGE_SIZE, 0),
    tableId,
    result: null,
    explainResult: null,
    error: null,
  }
}

function createInitialTabState() {
  const initialTab = createBlankTab(0)
  return { tabs: [initialTab], activeTabId: initialTab.id }
}

type SqlEditorTabState = ReturnType<typeof createInitialTabState>

function openQueryInEditorState(
  state: SqlEditorTabState,
  sql: string,
): SqlEditorTabState | null {
  const trimmed = sql.trim()
  if (!trimmed) return null

  const existing = state.tabs.find((tab) => tab.sql === trimmed)
  if (existing) {
    return { ...state, activeTabId: existing.id }
  }

  const newTab: SqlEditorTab = {
    id: crypto.randomUUID(),
    title: queryPreviewLabel(trimmed),
    sql: trimmed,
    result: null,
    explainResult: null,
    error: null,
  }

  return {
    tabs: [...state.tabs, newTab],
    activeTabId: newTab.id,
  }
}

function openTableInEditorState(
  state: SqlEditorTabState,
  tableId: string,
): SqlEditorTabState {
  const normalizedTableId = normalizeMysqlTableRouteId(tableId)
  const existing = state.tabs.find(
    (tab) => tab.tableId === normalizedTableId,
  )
  if (existing) {
    return { ...state, activeTabId: existing.id }
  }

  const newTab = createTableTab(normalizedTableId)
  return {
    tabs: [...state.tabs, newTab],
    activeTabId: newTab.id,
  }
}

function createBlankEditorTabState(state: SqlEditorTabState): SqlEditorTabState {
  const newTab = createBlankTab(state.tabs.length)
  return {
    tabs: [...state.tabs, newTab],
    activeTabId: newTab.id,
  }
}

function duplicateQueryInEditorState(
  state: SqlEditorTabState,
  sql: string,
): SqlEditorTabState {
  const trimmed = sql.trim()
  const newTab: SqlEditorTab = {
    id: crypto.randomUUID(),
    title: queryPreviewLabel(trimmed || DEFAULT_SQL),
    sql: trimmed || DEFAULT_SQL,
    result: null,
    explainResult: null,
    error: null,
  }

  return {
    tabs: [...state.tabs, newTab],
    activeTabId: newTab.id,
  }
}

function closeOtherEditorTabsState(
  state: SqlEditorTabState,
  tabId: string,
): SqlEditorTabState | null {
  const tab = state.tabs.find((entry) => entry.id === tabId)
  if (!tab || state.tabs.length <= 1) return null

  return {
    tabs: [tab],
    activeTabId: tabId,
  }
}

function closeEditorTabState(
  state: SqlEditorTabState,
  tabId: string,
): SqlEditorTabState {
  if (state.tabs.length <= 1) return state

  const index = state.tabs.findIndex((tab) => tab.id === tabId)
  if (index < 0) return state

  const tabs = state.tabs.filter((tab) => tab.id !== tabId)
  if (tabId !== state.activeTabId) {
    return { ...state, tabs }
  }

  const nextIndex = Math.min(index, tabs.length - 1)
  return {
    tabs,
    activeTabId: tabs[nextIndex]?.id ?? tabs[0].id,
  }
}

function editorTabStateFromPersisted(
  persisted: PersistedMysqlSqlEditorState,
): SqlEditorTabState {
  const tabs: SqlEditorTab[] = persisted.tabs.map((tab) => ({
    id: tab.id,
    title: tab.title,
    sql: tab.sql,
    tableId: tab.tableId,
    result: null,
    explainResult: null,
    error: null,
  }))
  const activeTabId = tabs.some((tab) => tab.id === persisted.activeTabId)
    ? persisted.activeTabId
    : tabs[0]?.id

  return { tabs, activeTabId }
}

function persistedFromEditorTabState(
  state: SqlEditorTabState,
): PersistedMysqlSqlEditorState {
  const tabs = state.tabs.map((tab) => ({
    id: tab.id,
    title: tab.title.slice(0, MAX_MYSQL_SQL_EDITOR_TAB_TITLE_LENGTH),
    sql: tab.sql,
    ...(tab.tableId ? { tableId: tab.tableId } : {}),
  }))
  const activeTabId = tabs.some((tab) => tab.id === state.activeTabId)
    ? state.activeTabId
    : tabs[0]?.id ?? state.activeTabId

  return { tabs, activeTabId }
}

type MysqlSidebarProviderProps = {
  databaseId: string
  children: ReactNode
}

export function MysqlSidebarProvider({
  databaseId,
  children,
}: MysqlSidebarProviderProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { account } = useAuth()
  const { project } = useProject(projectId)
  const teamId = project?.teamId ?? null
  const { savedQueryLevel, setSavedQueryLevel } = useMysqlSavedQueryScope(
    databaseId,
    account,
    teamId,
  )
  const { recentQueries, persistRecentQueries } = useMysqlQueryHistory(
    databaseId,
    account,
  )

  const { panel, setPanel } = useMysqlSidebarPanel(databaseId, account)
  const { schemas: sidebarSchemas } = useMysqlSidebarSchemas(
    projectId,
    databaseId,
    '',
  )
  const { selectedSchema, setSelectedSchema } = useMysqlSelectedSchema(
    databaseId,
    sidebarSchemas,
    account,
  )
  const { parseInitialEditorState, persistEditorTabState } =
    useMysqlSqlEditorPersistence(databaseId, account)
  const [selectedQueryKey, setSelectedQueryKey] = useState<string | null>(null)
  const skipEditorPersistRef = useRef(true)
  const editorInitializedDatabaseIdRef = useRef<string | null>(null)
  const pendingActiveTabIdRef = useRef<string | null>(null)
  const [editorTabState, setEditorTabState] = useState(createInitialTabState)
  const { tabs, activeTabId } = editorTabState

  useEffect(() => {
    editorInitializedDatabaseIdRef.current = null
  }, [databaseId])

  useEffect(() => {
    if (!databaseId) return
    if (editorInitializedDatabaseIdRef.current === databaseId) return
    if (!account) return

    skipEditorPersistRef.current = true
    const persisted = parseInitialEditorState()
    setEditorTabState(
      persisted ? editorTabStateFromPersisted(persisted) : createInitialTabState(),
    )
    editorInitializedDatabaseIdRef.current = databaseId
    requestAnimationFrame(() => {
      skipEditorPersistRef.current = false
    })
  }, [account, databaseId, parseInitialEditorState])

  useEffect(() => {
    if (skipEditorPersistRef.current || !databaseId) return
    persistEditorTabState(persistedFromEditorTabState(editorTabState))
  }, [databaseId, editorTabState, persistEditorTabState])

  const activeTab = useMemo(
    () => tabs.find((tab) => tab.id === activeTabId) ?? tabs[0],
    [activeTabId, tabs],
  )

  const applyPendingActiveTab = useCallback(() => {
    const tabId = pendingActiveTabIdRef.current
    if (!tabId) return

    flushSync(() => {
      setEditorTabState((prev) => {
        if (!prev.tabs.some((tab) => tab.id === tabId)) {
          pendingActiveTabIdRef.current = null
          return prev
        }
        if (prev.activeTabId === tabId) {
          pendingActiveTabIdRef.current = null
          return prev
        }
        pendingActiveTabIdRef.current = null
        return { ...prev, activeTabId: tabId }
      })
    })
  }, [])

  const schedulePendingActiveTab = useCallback(() => {
    queueMicrotask(() => applyPendingActiveTab())
    requestAnimationFrame(() => applyPendingActiveTab())
  }, [applyPendingActiveTab])

  const commitEditorTabState = useCallback(
    (
      updater: (state: SqlEditorTabState) => SqlEditorTabState | null,
      options?: { focusTab?: boolean },
    ) => {
      let nextActiveTabId: string | null = null

      flushSync(() => {
        setEditorTabState((prev) => {
          const next = updater(prev)
          if (!next) return prev
          if (options?.focusTab !== false) {
            nextActiveTabId = next.activeTabId
          }
          return next
        })
      })

      if (nextActiveTabId) {
        pendingActiveTabIdRef.current = nextActiveTabId
      }

      return nextActiveTabId
    },
    [],
  )

  const focusTableRouteTab = useCallback(
    (tableId: string) => {
      const normalizedTableId = normalizeMysqlTableRouteId(tableId)
      commitEditorTabState((prev) =>
        openTableInEditorState(prev, normalizedTableId),
      )
    },
    [commitEditorTabState],
  )

  const setActiveTabId = useCallback((tabId: string) => {
    pendingActiveTabIdRef.current = null
    setEditorTabState((prev) => {
      if (!prev.tabs.some((tab) => tab.id === tabId)) return prev
      return prev.activeTabId === tabId ? prev : { ...prev, activeTabId: tabId }
    })
  }, [])

  const updateActiveTabSql = useCallback((sql: string) => {
    setEditorTabState((prev) => ({
      ...prev,
      tabs: prev.tabs.map((tab) =>
        tab.id === prev.activeTabId ? { ...tab, sql } : tab,
      ),
    }))
  }, [])

  const setActiveTabResult = useCallback(
    (
      result: Models.DedicatedDatabaseExecution | null,
      error: unknown = null,
      resultKind: 'query' | 'explain' = 'query',
      explainResult: DedicatedDatabaseQueryExplanation | null = null,
    ) => {
      setEditorTabState((prev) => ({
        ...prev,
        tabs: prev.tabs.map((tab) =>
          tab.id === prev.activeTabId
            ? {
                ...tab,
                result: resultKind === 'explain' ? null : result,
                explainResult:
                  resultKind === 'explain' ? explainResult : null,
                error,
                resultKind,
              }
            : tab,
        ),
      }))
    },
    [],
  )

  const addRecentQuery = useCallback(
    (sql: string) => {
      const trimmed = sql.trim()
      if (!trimmed) return

      const existing = recentQueries.find((entry) => entry.sql === trimmed)
      const nextEntry: MysqlRecentQuery = {
        id: existing?.id ?? crypto.randomUUID(),
        sql: trimmed,
        ranAt: Date.now(),
      }
      const without = recentQueries.filter((entry) => entry.sql !== trimmed)
      persistRecentQueries(
        [nextEntry, ...without].slice(0, MAX_MYSQL_QUERY_HISTORY_ENTRIES),
      )
    },
    [persistRecentQueries, recentQueries],
  )

  const clearRecentQueries = useCallback(() => {
    persistRecentQueries([])
    setSelectedQueryKey((key) =>
      key?.startsWith('recent:') ? null : key,
    )
  }, [persistRecentQueries])

  const openQueryTab = useCallback(
    (sql: string) => {
      const nextActiveTabId = commitEditorTabState((prev) =>
        openQueryInEditorState(prev, sql),
      )
      if (!nextActiveTabId) return

      navigate({
        ...mysqlNav({ projectId, databaseId }).sql(),
      })
      schedulePendingActiveTab()
    },
    [
      commitEditorTabState,
      databaseId,
      navigate,
      projectId,
      schedulePendingActiveTab,
    ],
  )

  const selectRecentQuery = useCallback(
    (query: MysqlRecentQuery) => {
      setSelectedQueryKey(mysqlQuerySelectionKey('recent', query.id))
      openQueryTab(query.sql)
    },
    [openQueryTab],
  )

  const selectSavedQuery = useCallback(
    (level: MysqlSavedQueryLevel, query: SavedMysqlQuery) => {
      setSelectedQueryKey(mysqlQuerySelectionKey(level, query.id))
      openQueryTab(query.sql)
    },
    [openQueryTab],
  )

  const openTableInEditor = useCallback(
    (tableId: string) => {
      const normalizedTableId = normalizeMysqlTableRouteId(tableId)
      navigate({
        ...mysqlTableRows({
          projectId,
          databaseId,
          tableId: normalizedTableId,
        }),
        replace: true,
      })
    },
    [databaseId, navigate, projectId],
  )

  const openTableInSqlEditor = useCallback(
    (tableId: string) => {
      const normalizedTableId = normalizeMysqlTableRouteId(tableId)
      focusTableRouteTab(normalizedTableId)
      navigate({
        ...mysqlNav({ projectId, databaseId }).sql(),
        replace: true,
      })
      schedulePendingActiveTab()
    },
    [
      databaseId,
      focusTableRouteTab,
      navigate,
      projectId,
      schedulePendingActiveTab,
    ],
  )

  const createTab = useCallback(() => {
    commitEditorTabState((prev) => createBlankEditorTabState(prev))
    setSelectedQueryKey(null)
    schedulePendingActiveTab()
  }, [commitEditorTabState, schedulePendingActiveTab])

  const closeTab = useCallback(
    (tabId: string) => {
      commitEditorTabState((prev) => closeEditorTabState(prev, tabId), {
        focusTab: false,
      })
    },
    [commitEditorTabState],
  )

  const closeOtherTabs = useCallback(
    (tabId: string) => {
      commitEditorTabState((prev) => closeOtherEditorTabsState(prev, tabId), {
        focusTab: false,
      })
    },
    [commitEditorTabState],
  )

  const duplicateQueryTab = useCallback(
    (sql: string) => {
      commitEditorTabState((prev) => duplicateQueryInEditorState(prev, sql))
      setSelectedQueryKey(null)
      navigate({
        ...mysqlNav({ projectId, databaseId }).sql(),
      })
      schedulePendingActiveTab()
    },
    [
      commitEditorTabState,
      databaseId,
      navigate,
      projectId,
      schedulePendingActiveTab,
    ],
  )

  const reorderTabs = useCallback((activeId: string, overId: string) => {
    setEditorTabState((prev) => {
      const oldIndex = prev.tabs.findIndex((tab) => tab.id === activeId)
      const newIndex = prev.tabs.findIndex((tab) => tab.id === overId)
      if (oldIndex < 0 || newIndex < 0 || oldIndex === newIndex) return prev

      const tabs = [...prev.tabs]
      const [moved] = tabs.splice(oldIndex, 1)
      tabs.splice(newIndex, 0, moved)
      return { ...prev, tabs }
    })
  }, [])

  const renameTab = useCallback((tabId: string, title: string) => {
    const trimmed = title.trim()
    if (!trimmed) return

    setEditorTabState((prev) => {
      if (!prev.tabs.some((tab) => tab.id === tabId)) return prev

      const nextTitle = trimmed.slice(0, MAX_MYSQL_SQL_EDITOR_TAB_TITLE_LENGTH)
      return {
        ...prev,
        tabs: prev.tabs.map((tab) =>
          tab.id === tabId ? { ...tab, title: nextTitle } : tab,
        ),
      }
    })
  }, [])

  const value = useMemo(
    () => ({
      panel,
      setPanel,
      selectedSchema,
      setSelectedSchema,
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
      openTableInEditor,
      openTableInSqlEditor,
      focusTableRouteTab,
      openQueryTab,
      createTab,
      closeTab,
      closeOtherTabs,
      duplicateQueryTab,
      reorderTabs,
      renameTab,
      setActiveTabId,
      updateActiveTabSql,
      setActiveTabResult,
    }),
    [
      panel,
      setPanel,
      selectedSchema,
      setSelectedSchema,
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
      openTableInEditor,
      openTableInSqlEditor,
      focusTableRouteTab,
      openQueryTab,
      createTab,
      closeTab,
      closeOtherTabs,
      duplicateQueryTab,
      reorderTabs,
      renameTab,
      updateActiveTabSql,
      setActiveTabResult,
    ],
  )

  return (
    <MysqlSidebarContext.Provider value={value}>
      {children}
    </MysqlSidebarContext.Provider>
  )
}

export function useMysqlSidebar() {
  const context = useContext(MysqlSidebarContext)
  if (!context) {
    throw new Error('useMysqlSidebar must be used within MysqlSidebarProvider')
  }
  return context
}
