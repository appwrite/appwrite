import { useMemo, useState, type ReactNode } from 'react'
import type { Models } from '@appwrite.io/console'
import { executionResultRows, formatPostgresSql } from '@/lib/postgres-sql'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ReadOnlyDataSpreadsheet } from '@/components/global/shared/ReadOnlyDataSpreadsheet'
import { PostgresQueryResultsMeta } from './_components/PostgresQueryResultsMeta'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertCircle } from 'lucide-react'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { PostgresSqlEditorContainer } from './PostgresSqlEditorContainer'
import { PostgresSqlCodeEditor } from './PostgresSqlCodeEditor'
import { SqlEditorActionBar } from './_components/SqlEditorActionBar'
import { SqlEditorTabBar } from './_components/SqlEditorTabBar'
import { SqlWorkbenchPanelEmptyState } from './_components/SqlWorkbenchPanelEmptyState'
import { SavePostgresQueryDialog } from './_components/SavePostgresQueryDialog'
import type { SqlEditorTab } from './_components/PostgresSidebarContext'

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
  onRun: () => void
  isRunning: boolean
  error: unknown
  result: Models.DedicatedDatabaseExecution | null
  account: { prefs?: Record<string, unknown> } | undefined
  teamId: string | null | undefined
  canSaveTeam: boolean
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
  onRun,
  isRunning,
  error,
  result,
  account,
  teamId,
  canSaveTeam,
  children,
}: SqlWorkbenchProps) {
  const [saveDialogOpen, setSaveDialogOpen] = useState(false)
  const hasSql = !!sql.trim()
  const canRunQuery = !isRunning && hasSql
  const canSaveQuery = hasSql
  const canFormatQuery = hasSql
  const errorMessage = error ? getErrorMessage(error) : null
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

  return (
    <>
    <PostgresSqlEditorContainer
      className="h-full min-h-0 flex-1"
      editor={
        <div className="relative flex h-full min-h-0 flex-col">
          <div className="shrink-0 bg-background">
            <ServiceHeader
              title="SQL editor"
              fullWidthBorder
              fullWidth
              contentAfterBorder={
                <div className="pt-2">
                  <SqlEditorTabBar
                    tabs={tabs}
                    activeTabId={activeTabId}
                    onSelectTab={onSelectTab}
                    onCreateTab={onCreateTab}
                    onCloseTab={onCloseTab}
                    onReorderTabs={onReorderTabs}
                  />
                </div>
              }
            />
          </div>
          <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden">
            <PostgresSqlCodeEditor
              projectId={projectId}
              databaseId={databaseId}
              tabId={activeTabId}
              sql={sql}
              onSqlChange={onSqlChange}
              onRun={onRun}
              canRun={canRunQuery}
            />
            <SqlEditorActionBar
              canSave={canSaveQuery}
              canFormat={canFormatQuery}
              canRun={canRunQuery}
              isRunning={isRunning}
              onSave={() => setSaveDialogOpen(true)}
              onFormat={() => onSqlChange(formatPostgresSql(sql))}
              onRun={onRun}
            />
          </div>
        </div>
      }
    >
      {errorMessage ? (
        <div className="shrink-0 border-b border-border px-4 py-3 sm:px-6">
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>Query failed</AlertTitle>
            <AlertDescription className="text-[13px]">
              {errorMessage}
            </AlertDescription>
          </Alert>
        </div>
      ) : null}

      {result ? (
        <ReadOnlyDataSpreadsheet
          className="min-h-0 flex-1"
          variant="studio"
          showRowNumbers
          columns={resultColumns}
          rows={resultRows}
          getRowKey={(_, index) => `sql-result-${index}`}
          emptyContent={<SqlWorkbenchPanelEmptyState variant="query-no-rows" />}
          header={
            <PostgresQueryResultsMeta
              title="Query results"
              rowCount={result.rowCount}
              durationMs={result.durationMs}
              truncated={result.truncated}
            />
          }
        />
      ) : (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
          {children}
        </div>
      )}
    </PostgresSqlEditorContainer>
    <SavePostgresQueryDialog
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
