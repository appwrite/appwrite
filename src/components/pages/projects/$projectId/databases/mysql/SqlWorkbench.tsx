import { useEffect, useMemo, useRef, useState, useCallback, type ReactNode } from 'react'
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import type { DedicatedDatabaseQueryExplanation } from '@/lib/databases/dedicated-engine'
import { executionResultRows, formatMysqlSql } from '@/lib/mysql-sql'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import {
  registerMysqlSqlEditorActions,
  openMysqlSqlJumpToTabPicker,
  type MysqlSqlEditorActions,
} from '@/lib/mysql-sql-editor-actions'
import { useMysqlSqlEditorShortcuts } from '@/lib/mysql-sql-editor/use-mysql-sql-editor-shortcuts'
import { ReadOnlyDataSpreadsheet } from '@/components/global/shared/ReadOnlyDataSpreadsheet'
import { MysqlQueryResultsMeta } from './_components/MysqlQueryResultsMeta'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertCircle } from 'lucide-react'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { MysqlSqlEditorContainer } from './MysqlSqlEditorContainer'
import { MysqlSqlCodeEditor } from './MysqlSqlCodeEditor'
import type { MysqlSqlCodeEditorRef } from './MysqlSqlCodeEditor'
import { SqlEditorActionBar } from './_components/SqlEditorActionBar'
import { SqlEditorTabBar } from './_components/SqlEditorTabBar'
import { SqlWorkbenchPanelEmptyState } from './_components/SqlWorkbenchPanelEmptyState'
import { MysqlQueryPlanView } from './_components/MysqlQueryPlanView'
import { SaveMysqlQueryDialog } from './_components/SaveMysqlQueryDialog'
import { MYSQL_SQL_EDITOR_SURFACE_CLASS } from './_components/mysql-chrome'
import type { SqlEditorTab } from './_components/MysqlSidebarContext'
import { useT } from '@/lib/i18n/translate'

type SqlWorkbenchProps = {
  projectId: string
  databaseId: string
  tabs: SqlEditorTab[]
  activeTabId: string
  sql: string
  onSqlChange: (value: string) => void
  onSelectTab: (tabId: string) => void
  onCreateTab: () => void
  onCloseTab: (tabId: string) => void
  onReorderTabs: (activeId: string, overId: string) => void
  onRenameTab: (tabId: string, title: string) => void
  onRun: () => void
  onExplain: () => void
  isRunning: boolean
  isExplaining: boolean
  error: unknown
  result: Models.DedicatedDatabaseExecution | null
  explainResult: DedicatedDatabaseQueryExplanation | null
  resultKind?: 'query' | 'explain'
  account: { prefs?: Record<string, unknown> } | undefined
  teamId: string | null | undefined
  canSaveTeam: boolean
  canRun?: boolean
  runDisabledTooltip?: string
  canExplain?: boolean
  explainDisabledTooltip?: string
  /** Content below SQL results (e.g. selected table rows). */
  children?: ReactNode
}

export function SqlWorkbench({
  projectId,
  databaseId,
  tabs,
  activeTabId,
  sql,
  onSqlChange,
  onSelectTab,
  onCreateTab,
  onCloseTab,
  onReorderTabs,
  onRenameTab,
  onRun,
  onExplain,
  isRunning,
  isExplaining,
  error,
  result,
  explainResult,
  resultKind = 'query',
  account,
  teamId,
  canSaveTeam,
  canRun: operationsEnabled = true,
  runDisabledTooltip,
  canExplain: explainEnabled = true,
  explainDisabledTooltip,
  children,
}: SqlWorkbenchProps) {
  const t = useT()
  const [saveDialogOpen, setSaveDialogOpen] = useState(false)
  const [isHeaderCollapsed, setIsHeaderCollapsed] = useState(false)
  const [canUndo, setCanUndo] = useState(false)
  const [canRedo, setCanRedo] = useState(false)
  const sqlEditorRef = useRef<MysqlSqlCodeEditorRef>(null)
  const hasSql = !!sql.trim()
  const isBusy = isRunning || isExplaining
  const canRunQuery = operationsEnabled && !isBusy && hasSql
  const canExplainQuery = explainEnabled && !isBusy && hasSql
  const canSaveQuery = hasSql
  const canFormatQuery = hasSql
  const errorMessage = error ? getErrorMessage(error) : null
  const errorTitle = resultKind === 'explain' ? 'Explain failed' : 'Query failed'
  const loadingLabel = isExplaining ? 'Explaining query…' : 'Running query…'
  const resultRows = useMemo(
    () => (result ? executionResultRows<Record<string, unknown>>(result) : []),
    [result],
  )
  const resultColumns = useMemo(() => {
    if (result?.columns?.length) {
      return result.columns.map((column) => ({
        key: column.name,
        label: column.name,
      }))
    }
    const first = resultRows[0]
    return first
      ? Object.keys(first).map((key) => ({ key, label: key }))
      : []
  }, [result?.columns, resultRows])
  const showQueryResults =
    result != null || explainResult != null || isBusy
  const showExplainResults =
    isExplaining || (resultKind === 'explain' && !isRunning)

  const canSwitchTabs = tabs.length > 1
  const canCloseTab = tabs.length > 1

  const tabRefs = useMemo(
    () => tabs.map((tab) => ({ id: tab.id, title: tab.title })),
    [tabs],
  )

  const selectNextTab = useCallback(() => {
    if (!canSwitchTabs) return
    const currentIndex = tabs.findIndex((tab) => tab.id === activeTabId)
    if (currentIndex < 0) return
    onSelectTab(tabs[(currentIndex + 1) % tabs.length].id)
  }, [activeTabId, canSwitchTabs, onSelectTab, tabs])

  const selectPreviousTab = useCallback(() => {
    if (!canSwitchTabs) return
    const currentIndex = tabs.findIndex((tab) => tab.id === activeTabId)
    if (currentIndex < 0) return
    onSelectTab(tabs[(currentIndex - 1 + tabs.length) % tabs.length].id)
  }, [activeTabId, canSwitchTabs, onSelectTab, tabs])

  const selectTabByIndex = useCallback(
    (index: number) => {
      if (tabs.length === 0) return
      const targetIndex = index === 9 ? tabs.length - 1 : index - 1
      if (targetIndex < 0 || targetIndex >= tabs.length) return
      onSelectTab(tabs[targetIndex].id)
    },
    [onSelectTab, tabs],
  )

  const selectTab = useCallback(
    (tabId: string) => {
      if (tabs.some((tab) => tab.id === tabId)) onSelectTab(tabId)
    },
    [onSelectTab, tabs],
  )

  const closeActiveTab = useCallback(() => {
    if (!canCloseTab) return
    onCloseTab(activeTabId)
  }, [activeTabId, canCloseTab, onCloseTab])

  useEffect(() => {
    setCanUndo(false)
    setCanRedo(false)
  }, [activeTabId])

  const editorActions = useMemo<MysqlSqlEditorActions>(
    () => ({
      canUndo,
      canRedo,
      canSave: canSaveQuery,
      canFormat: canFormatQuery,
      canRun: canRunQuery,
      canExplain: canExplainQuery,
      canCreateTab: true,
      canCloseTab,
      canSelectNextTab: canSwitchTabs,
      canSelectPreviousTab: canSwitchTabs,
      canJumpToTab: tabs.length > 0,
      tabs: tabRefs,
      undo: () => sqlEditorRef.current?.undo(),
      redo: () => sqlEditorRef.current?.redo(),
      save: () => setSaveDialogOpen(true),
      format: () => onSqlChange(formatMysqlSql(sql)),
      run: onRun,
      explain: onExplain,
      createTab: onCreateTab,
      closeTab: closeActiveTab,
      selectNextTab,
      selectPreviousTab,
      selectTabByIndex,
      selectTab,
      openJumpToTabPicker: openMysqlSqlJumpToTabPicker,
    }),
    [
      canCloseTab,
      canExplainQuery,
      canFormatQuery,
      canRedo,
      canRunQuery,
      canSaveQuery,
      canSwitchTabs,
      canUndo,
      closeActiveTab,
      onCreateTab,
      onExplain,
      onRun,
      onSqlChange,
      selectNextTab,
      selectPreviousTab,
      selectTab,
      selectTabByIndex,
      sql,
      tabRefs,
      tabs.length,
    ],
  )

  useEffect(() => {
    registerMysqlSqlEditorActions(editorActions)
    return () => registerMysqlSqlEditorActions(null)
  }, [editorActions])

  useMysqlSqlEditorShortcuts(editorActions)

  return (
    <>
    <MysqlSqlEditorContainer
      className="h-full min-h-0 flex-1"
      editor={
        <div
          className="relative flex h-full min-h-0 flex-col"
          data-mysql-sql-workbench
        >
          <div className="relative z-20 shrink-0 overflow-hidden bg-background">
            <ServiceHeader
              title={t('SQL editor')}
              fullWidthBorder
              fullWidth
              hideTitle={isHeaderCollapsed}
              contentAfterBorder={
                <SqlEditorTabBar
                  projectId={projectId}
                  databaseId={databaseId}
                  tabs={tabs}
                  activeTabId={activeTabId}
                  onSelectTab={onSelectTab}
                  onCreateTab={onCreateTab}
                  onCloseTab={onCloseTab}
                  onReorderTabs={onReorderTabs}
                  onRenameTab={onRenameTab}
                  headerCollapsed={isHeaderCollapsed}
                  onToggleHeaderCollapsed={() =>
                    setIsHeaderCollapsed((collapsed) => !collapsed)
                  }
                />
              }
            />
          </div>
          <div
            className={cn(
              'flex min-h-0 flex-1 flex-col overflow-hidden',
              MYSQL_SQL_EDITOR_SURFACE_CLASS,
            )}
          >
            <MysqlSqlCodeEditor
              ref={sqlEditorRef}
              projectId={projectId}
              databaseId={databaseId}
              tabId={activeTabId}
              sql={sql}
              onSqlChange={onSqlChange}
              onUndoRedoStateChange={({ canUndo: nextCanUndo, canRedo: nextCanRedo }) => {
                setCanUndo(nextCanUndo)
                setCanRedo(nextCanRedo)
              }}
            />
            <SqlEditorActionBar
              canUndo={canUndo}
              canRedo={canRedo}
              onUndo={() => sqlEditorRef.current?.undo()}
              onRedo={() => sqlEditorRef.current?.redo()}
              canSave={canSaveQuery}
              canFormat={canFormatQuery}
              canRun={canRunQuery}
              canExplain={canExplainQuery}
              isRunning={isRunning}
              isExplaining={isExplaining}
              onSave={() => setSaveDialogOpen(true)}
              onFormat={() => onSqlChange(formatMysqlSql(sql))}
              onRun={onRun}
              onExplain={onExplain}
              runDisabledTooltip={
                !operationsEnabled ? runDisabledTooltip : undefined
              }
              explainDisabledTooltip={
                !explainEnabled ? explainDisabledTooltip : undefined
              }
            />
          </div>
        </div>
      }
    >
      {errorMessage ? (
        <div className="shrink-0 border-b border-border px-4 py-3 sm:px-6">
          <Alert>
            <AlertCircle className="h-4 w-4 text-muted-foreground" />
            <AlertTitle>{errorTitle}</AlertTitle>
            <AlertDescription className="text-[13px]">
              {errorMessage}
            </AlertDescription>
          </Alert>
        </div>
      ) : null}

      {showQueryResults ? (
        showExplainResults ? (
          <MysqlQueryPlanView
            className="min-h-0 flex-1"
            explanation={explainResult}
            isLoading={isExplaining && !explainResult}
            loadingLabel={loadingLabel}
          />
        ) : (
        <ReadOnlyDataSpreadsheet
          className="min-h-0 flex-1"
          variant="studio"
          showRowNumbers
          enableColumnResize
          columns={resultColumns}
          rows={resultRows}
          getRowKey={(_, index) => `sql-result-${index}`}
          isLoading={isBusy && !result}
          loadingLabel={loadingLabel}
          emptyContent={<SqlWorkbenchPanelEmptyState variant="query-no-rows" />}
          header={
            result ? (
              <MysqlQueryResultsMeta
                title={t('Query results')}
                rowCount={result.rowCount}
                durationMs={result.durationMs}
                truncated={result.truncated}
              />
            ) : undefined
          }
        />
        )
      ) : (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
          {children}
        </div>
      )}
    </MysqlSqlEditorContainer>
    <SaveMysqlQueryDialog
      open={saveDialogOpen}
      onOpenChange={setSaveDialogOpen}
      databaseId={databaseId}
      sql={sql}
      account={account}
      teamId={teamId}
      canSaveTeam={canSaveTeam}
    />
    </>
  )
}
