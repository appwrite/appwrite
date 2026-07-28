import { useMemo, useEffect } from 'react'
import { useParams, useLocation, useNavigate } from '@tanstack/react-router'
import {
  Download,
  Loader2,
  CheckCircle2,
  AlertCircle,
  FileDown,
} from 'lucide-react'
import { MessageDirectionIcon } from '@/components/pages/projects/$projectId/realtime/_components/MessageDirectionIcon'
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
import { Pagination } from '@/components/global/shared/Pagination'
import {
  GRID_DEFAULT_PAGE_SIZE,
  TABLE_WORKSPACE_TABLES_LIST_LIMIT,
} from '@/lib/react-query/hooks/constants'
import {
  getPage,
  getLimit,
  buildListSearchParams,
  urlFromRouterLocation,
} from '@/lib/table-filters'
import {
  useProjectTables,
  useDatabaseCsvMigrations,
} from '@/lib/react-query/hooks'
import { getMigrationTableRef } from '@/lib/migrations/csv-resource'
import { isDatabaseRouteKind, type DatabaseRouteKind } from '@/lib/database-routes'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

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
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const dbKind: DatabaseRouteKind = isDatabaseRouteKind(params.dbKind ?? '')
    ? (params.dbKind as DatabaseRouteKind)
    : 'tablesdb'
  const location = useLocation()
  const navigate = useNavigate()

  const { page: urlPage, limit: urlLimit } = useMemo(() => {
    if (typeof window === 'undefined') {
      return { page: 1, limit: GRID_DEFAULT_PAGE_SIZE }
    }
    const url = urlFromRouterLocation(location, window.location.origin)
    return {
      page: getPage(url, 1),
      limit: getLimit(url, GRID_DEFAULT_PAGE_SIZE),
    }
  }, [location.pathname, location.search])

  const { tables, isLoading: tablesLoading } = useProjectTables(
    projectId,
    databaseId,
    dbKind,
    0,
    TABLE_WORKSPACE_TABLES_LIST_LIMIT,
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

  const totalItems = rows.length
  const totalPages = Math.max(1, Math.ceil(totalItems / urlLimit))
  const effectivePage =
    totalItems === 0 ? urlPage : Math.min(urlPage, totalPages)

  useEffect(() => {
    if (totalItems === 0 || urlPage === effectivePage) return
    if (!projectId || !dbKind) return
    navigate({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
      params: { projectId, dbKind, databaseId },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            page: effectivePage,
            limit: urlLimit,
          }),
        }
        if (effectivePage === 1) delete next.page
        return next
      },
      replace: true,
    })
  }, [
    totalItems,
    urlPage,
    effectivePage,
    urlLimit,
    projectId,
    dbKind,
    databaseId,
    navigate,
  ])

  const paginatedRows = useMemo(() => {
    const start = (effectivePage - 1) * urlLimit
    return rows.slice(start, start + urlLimit)
  }, [rows, effectivePage, urlLimit])

  const handlePageChange = (page: number) => {
    if (!projectId || !dbKind) return
    navigate({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
      params: { projectId, dbKind, databaseId },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({ page, limit: urlLimit }),
        }
        if (page === 1) delete next.page
        return next
      },
      replace: true,
    })
  }

  const handlePageSizeChange = (newPageSize: number) => {
    if (!projectId || !dbKind) return
    navigate({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
      params: { projectId, dbKind, databaseId },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({ page: 1, limit: newPageSize }),
        }
        delete next.page
        return next
      },
      replace: true,
    })
  }

  const isLoading = tablesLoading || (tableIds.length > 0 && migrationsLoading)

  const shellClassName =
    'mx-auto w-full max-w-7xl px-4 pt-4 pb-4 sm:px-6 sm:pt-6 sm:pb-6'

  if (isLoading && rows.length === 0) {
    return (
      <div className={shellClassName}>
        <div className="rounded-lg border border-border bg-card py-12 text-center">
          <div className="text-muted-foreground">{t('Loading...')}</div>
        </div>
      </div>
    )
  }

  if (rows.length === 0) {
    return (
      <div className={shellClassName}>
        <EmptyState
          icon={FileDown}
          title={t('No export or import requests yet')}
          description={t(
            'When you export a table to CSV or import data from a CSV file, those requests will be listed here.',
          )}
          isEmpty={true}
          variant="card"
        />
      </div>
    )
  }

  return (
    <div className={cn(shellClassName, 'flex flex-col gap-2')}>
      <div className="rounded-lg border border-border bg-card overflow-x-auto overflow-y-visible">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                {t('Type')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[140px]">
                {t('Table')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[130px]">
                {t('Status')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px]">
                {t('Updated')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[100px] pe-4" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {paginatedRows.map(({ type, migration }) => {
              const isExport = type === 'export'
              const isCompleted = migration.status === 'completed'
              const downloadUrl = (
                migration.options as { downloadUrl?: string }
              )?.downloadUrl
              const tid = getMigrationTableRef(migration)?.tableId ?? ''
              const tableName = tableNameById[tid] ?? tid
              const status = migration.status

              return (
                <TableRow key={`${type}-${migration.$id}`}>
                  <TableCell className="px-4 py-3">
                    <span className="inline-flex items-center gap-1.5 text-[12px] font-medium text-foreground">
                      <MessageDirectionIcon
                        direction={isExport ? 'out' : 'in'}
                        className="h-3.5 w-3.5"
                      />
                      {isExport ? t('Export') : t('Import')}
                    </span>
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
                          {t(statusBadge.label)}
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
                  <TableCell className="px-4 py-3 text-end pe-4">
                    {isExport && isCompleted && downloadUrl ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 gap-1.5 text-[12px] font-medium"
                        onClick={() => window.open(downloadUrl, '_blank')}
                      >
                        <Download className="h-3.5 w-3.5" />
                        {t('Download')}
                      </Button>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                         - 
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>
      <Pagination
        currentPage={effectivePage}
        totalItems={totalItems}
        pageSize={urlLimit}
        pageSizeOptions={[12, 18, 36, 72]}
        onPageChange={handlePageChange}
        onPageSizeChange={handlePageSizeChange}
        itemLabel={t('requests')}
      />
    </div>
  )
}
