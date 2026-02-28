import { useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  Download,
  Loader2,
  CheckCircle2,
  AlertCircle,
  FileDown,
  FileUp,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  useProjectTables,
  useDatabaseCsvMigrations,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'

const TABLES_PAGE_SIZE = 500

/** resourceId format is "databaseId:tableId" */
function tableIdFromResourceId(resourceId: string): string {
  const idx = resourceId.indexOf(':')
  return idx >= 0 ? resourceId.slice(idx + 1) : resourceId
}

interface ExportImportViewProps {
  databaseId: string
}

type MigrationRow = {
  type: 'export' | 'import'
  migration: Models.Migration
}

function getMigrationStatusBadge(status: string): {
  variant: 'completed' | 'failed' | 'processing' | 'pending' | 'secondary'
  icon: typeof Loader2
  label: string
} {
  switch (status) {
    case 'completed':
      return { variant: 'completed', icon: CheckCircle2, label: 'Completed' }
    case 'failed':
      return { variant: 'failed', icon: AlertCircle, label: 'Failed' }
    case 'processing':
      return { variant: 'processing', icon: Loader2, label: 'Processing' }
    case 'pending':
      return { variant: 'pending', icon: Loader2, label: 'Pending' }
    default:
      return { variant: 'secondary', icon: AlertCircle, label: status }
  }
}

export function ExportImportView({ databaseId }: ExportImportViewProps) {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string

  const { tables, isLoading: tablesLoading } = useProjectTables(
    projectId,
    databaseId,
    0,
    TABLES_PAGE_SIZE,
    undefined,
  )
  const tableIds = useMemo(() => tables.map((t) => t.$id), [tables])
  const { migrations, isLoading: migrationsLoading } = useDatabaseCsvMigrations(
    projectId,
    databaseId,
    tableIds,
  )

  const tableNameById = useMemo(
    () => Object.fromEntries(tables.map((t) => [t.$id, t.name ?? t.$id])),
    [tables],
  )

  const rows = useMemo((): MigrationRow[] => {
    return migrations.map((m) => ({
      type: m.destination === 'CSV' ? ('export' as const) : ('import' as const),
      migration: m,
    }))
  }, [migrations])

  const isLoading = tablesLoading || (tableIds.length > 0 && migrationsLoading)

  if (isLoading && rows.length === 0) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <div className="text-muted-foreground">Loading...</div>
        </div>
      </div>
    )
  }

  if (rows.length === 0) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
        <div className="rounded-lg border border-border bg-card py-12">
          <EmptyState
            icon={FileDown}
            iconSize="lg"
            title="No export or import requests yet"
            description="When you export a table to CSV or import data from a CSV file, those requests will be listed here."
          />
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
      <div className="rounded-lg border border-border bg-card overflow-x-auto overflow-y-visible">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                Type
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[140px]">
                Table
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[130px]">
                Status
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px]">
                Updated
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px] pr-4" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map(({ type, migration }) => {
              const isExport = type === 'export'
              const isCompleted = migration.status === 'completed'
              const downloadUrl = (
                migration.options as { downloadUrl?: string }
              )?.downloadUrl
              const tid = tableIdFromResourceId(migration.resourceId)
              const tableName = tableNameById[tid] ?? tid
              const status = migration.status

              return (
                <TableRow key={`${type}-${migration.$id}`}>
                  <TableCell className="px-4 py-3">
                    <Badge
                      variant="outline"
                      className={cn(
                        'text-[11px] font-medium border',
                        isExport
                          ? 'border-sky-500/30 text-sky-700 dark:text-sky-400'
                          : 'border-violet-500/30 text-violet-700 dark:text-violet-400',
                      )}
                    >
                      <span className="inline-flex items-center gap-1">
                        {isExport ? (
                          <FileDown className="h-3 w-3 shrink-0" />
                        ) : (
                          <FileUp className="h-3 w-3 shrink-0" />
                        )}
                        {isExport ? 'Export' : 'Import'}
                      </span>
                    </Badge>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <code className="text-[12px] font-mono text-foreground bg-muted/50 px-1.5 py-0.5 rounded">
                      {tableName}
                    </code>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {(() => {
                      const statusBadge = getMigrationStatusBadge(status)
                      const StatusIcon = statusBadge.icon
                      const isSpinner =
                        status === 'pending' || status === 'processing'
                      return (
                        <Badge
                          variant={statusBadge.variant}
                          className="gap-1.5 text-[11px] font-medium"
                        >
                          <StatusIcon
                            className={cn(
                              'h-3 w-3 shrink-0',
                              isSpinner && 'animate-spin',
                            )}
                          />
                          {statusBadge.label}
                        </Badge>
                      )
                    })()}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip
                      date={migration.$updatedAt}
                      className="text-[12px] font-medium text-muted-foreground"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-right pr-4">
                    {isExport && isCompleted && downloadUrl ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 gap-1.5 text-[12px] font-medium"
                        onClick={() => window.open(downloadUrl, '_blank')}
                      >
                        <Download className="h-3.5 w-3.5" />
                        Download
                      </Button>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        —
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
