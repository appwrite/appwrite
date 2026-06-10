import { useMemo, type ReactNode } from 'react'
import type { Models } from '@appwrite.io/console'
import { executionResultRows, formatPostgresSql } from '@/lib/postgres-sql'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ReadOnlyDataSpreadsheet } from '@/components/global/shared/ReadOnlyDataSpreadsheet'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertCircle } from 'lucide-react'
import { PostgresSqlEditorContainer } from './PostgresSqlEditorContainer'
import { PostgresSqlCodeEditor } from './PostgresSqlCodeEditor'

type SqlWorkbenchProps = {
  projectId: string
  databaseId: string
  sql: string
  onSqlChange: (value: string) => void
  onRun: () => void
  isRunning: boolean
  error: unknown
  result: Models.DedicatedDatabaseExecution | null
  /** Content below SQL results (e.g. selected table rows). */
  children?: ReactNode
}

export function SqlWorkbench({
  projectId,
  databaseId,
  sql,
  onSqlChange,
  onRun,
  isRunning,
  error,
  result,
  children,
}: SqlWorkbenchProps) {
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
    <PostgresSqlEditorContainer
      className="h-full min-h-0 flex-1"
      editor={
        <div className="flex h-full min-h-0 flex-col">
          <div className="flex h-11 shrink-0 items-center justify-end gap-2 border-b border-border bg-muted/20 px-4 sm:px-6">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-7 px-3 text-[12px]"
              onClick={() => onSqlChange(formatPostgresSql(sql))}
              disabled={!sql.trim()}
            >
              Format
            </Button>
            <Button
              type="button"
              variant="brandCta"
              size="sm"
              className="h-7 px-3 text-[12px]"
              onClick={onRun}
              disabled={isRunning || !sql.trim()}
            >
              Run query
            </Button>
          </div>
          <PostgresSqlCodeEditor
            projectId={projectId}
            databaseId={databaseId}
            sql={sql}
            onSqlChange={onSqlChange}
          />
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
            columns={resultColumns}
            rows={resultRows}
            getRowKey={(_, index) => `sql-result-${index}`}
            emptyLabel="Query completed with no rows returned."
            header={
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-[13px] font-semibold text-foreground">
                  Query results
                </span>
                <Badge variant="info" className="text-[10px] shrink-0">
                  {result.rowCount} row{result.rowCount === 1 ? '' : 's'}
                </Badge>
                <Badge variant="info" className="text-[10px] shrink-0">
                  {result.durationMs} ms
                </Badge>
                {result.truncated ? (
                  <Badge variant="warning" className="text-[10px] shrink-0">
                    Truncated
                  </Badge>
                ) : null}
              </div>
            }
          />
        ) : (
          <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {children}
          </div>
        )}
    </PostgresSqlEditorContainer>
  )
}
