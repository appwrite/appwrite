import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useLocation } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  deleteProjectTableRow,
  useProjectTableRows,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { queryParamToMap } from '@/lib/table-filters'
import type { Collection } from '@/lib/utils/mock-data'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import {
  isDatabaseRouteKind,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { FileText } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

function documentPayloadJson(row: Record<string, unknown>): string {
  const o: Record<string, unknown> = {}
  for (const [k, v] of Object.entries(row)) {
    if (!k.startsWith('$')) o[k] = v
  }
  try {
    return JSON.stringify(o, null, 2)
  } catch {
    return '{}'
  }
}

type NavigateListParams = {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}

type DocumentsJsonSpreadsheetProps = {
  table: Collection
  canWriteRows?: boolean
  rowsUrlSearch?: string
  rowsUrlPage?: number
  rowsUrlLimit?: number
  rowsFilterQueries?: string[]
  rowsFilterQueryString?: string
  rowsSortBy?: string
  rowsSortOrder?: 'asc' | 'desc'
  onNavigateToList: (params: NavigateListParams) => void
  onRefetchReady?: (refetch: () => Promise<unknown>) => void
  onRowsCountChange?: (count: number) => void
}

export function DocumentsJsonSpreadsheet({
  table,
  canWriteRows = true,
  rowsUrlSearch,
  rowsUrlPage = 1,
  rowsUrlLimit = ROWS_DEFAULT_PAGE_SIZE,
  rowsFilterQueries,
  rowsFilterQueryString,
  rowsSortBy = '$createdAt',
  rowsSortOrder = 'desc',
  onNavigateToList,
  onRefetchReady,
  onRowsCountChange,
}: DocumentsJsonSpreadsheetProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id
  const dbKindParam = params.dbKind as string | undefined
  const dbKind: DatabaseRouteKind =
    dbKindParam && isDatabaseRouteKind(dbKindParam) ? dbKindParam : 'tablesdb'
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, dbKind)
  const prevTableIdForPreviewRef = useRef<string | null>(null)
  const location = useLocation()
  const queryClient = useQueryClient()

  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set())
  const [previewRowId, setPreviewRowId] = useState<string | null>(null)
  const [requestedPage, setRequestedPage] = useState(rowsUrlPage)
  const [displayedPage, setDisplayedPage] = useState(rowsUrlPage)
  const [displayedSearch, setDisplayedSearch] = useState(rowsUrlSearch ?? '')
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState(rowsFilterQueryString ?? '')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const documentsPaneHasFilters = useMemo(
    () =>
      Boolean(
        (displayedSearch ?? '').trim() ||
          (displayedFilterQueryString ?? '').length,
      ),
    [displayedSearch, displayedFilterQueryString],
  )
  const [sortBy, setSortBy] = useState(rowsSortBy)
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>(rowsSortOrder)
  const [displayedSortBy, setDisplayedSortBy] = useState(rowsSortBy)
  const [displayedSortOrder, setDisplayedSortOrder] =
    useState<'asc' | 'desc'>(rowsSortOrder)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  /** After deleting the previewed doc, avoid auto-selecting another row on refetch. */
  const skipNextAutoPreviewRef = useRef(false)

  useEffect(() => {
    setRequestedPage(rowsUrlPage)
  }, [rowsUrlPage, rowsUrlLimit])

  useEffect(() => {
    setSortBy(rowsSortBy)
    setSortOrder(rowsSortOrder)
    setDisplayedSortBy(rowsSortBy)
    setDisplayedSortOrder(rowsSortOrder)
  }, [rowsSortBy, rowsSortOrder])

  useEffect(() => {
    setSelectedRows(new Set())
    setDeleteDialogOpen(false)
    setDisplayedPage(rowsUrlPage)
    setDisplayedSearch(rowsUrlSearch ?? '')
    setDisplayedFilterQueryString(rowsFilterQueryString ?? '')
    skipNextAutoPreviewRef.current = false
  }, [
    location.pathname,
    projectId,
    databaseId,
    tableId,
    rowsUrlPage,
    rowsUrlSearch,
    rowsFilterQueryString,
  ])

  useEffect(() => {
    const prev = prevTableIdForPreviewRef.current
    prevTableIdForPreviewRef.current = tableId
    if (prev !== null && prev !== tableId) {
      setPreviewRowId(null)
    }
  }, [tableId])

  const effectiveSearch = rowsUrlSearch ?? ''
  const effectivePageSize = rowsUrlLimit
  const effectiveFilterQueries = rowsFilterQueries

  const {
    total: rowsTotal,
    isLoading: rowsLoading,
    refetch,
    isFetching: rowsFetching,
  } = useProjectTableRows(
    projectId,
    databaseId,
    tableId,
    dbKind,
    requestedPage - 1,
    effectivePageSize,
    effectiveSearch,
    sortOrder,
    sortBy,
    effectiveFilterQueries,
  )

  const {
    rows: apiRows,
    total: displayedRowsTotal,
    isLoading: displayedRowsLoading,
  } = useProjectTableRows(
    projectId,
    databaseId,
    tableId,
    dbKind,
    displayedPage - 1,
    effectivePageSize,
    displayedSearch,
    displayedSortOrder,
    displayedSortBy,
    displayedFilterQueries,
  )

  useEffect(() => {
    if (rowsFetching || rowsLoading) return
    const urlSearchMatch = (rowsUrlSearch ?? '') === (displayedSearch ?? '')
    const filterMatch =
      (rowsFilterQueryString ?? '') === (displayedFilterQueryString ?? '')
    const match =
      requestedPage === displayedPage &&
      sortBy === displayedSortBy &&
      sortOrder === displayedSortOrder &&
      urlSearchMatch &&
      filterMatch
    if (!match) {
      setDisplayedPage(requestedPage)
      setDisplayedSortBy(sortBy)
      setDisplayedSortOrder(sortOrder)
      setDisplayedSearch(rowsUrlSearch ?? '')
      setDisplayedFilterQueryString(rowsFilterQueryString ?? '')
    }
  }, [
    rowsFetching,
    rowsLoading,
    requestedPage,
    displayedPage,
    sortBy,
    sortOrder,
    displayedSortBy,
    displayedSortOrder,
    rowsUrlSearch,
    displayedSearch,
    rowsFilterQueryString,
    displayedFilterQueryString,
  ])

  const showRowsLoading = displayedRowsLoading && apiRows.length === 0

  const prevRowsTotalRef = useRef<number | null>(null)
  useEffect(() => {
    const total = displayedRowsTotal ?? rowsTotal
    if (onRowsCountChange && prevRowsTotalRef.current !== total) {
      prevRowsTotalRef.current = total
      onRowsCountChange(total)
    }
  }, [displayedRowsTotal, rowsTotal, onRowsCountChange])

  useEffect(() => {
    if (onRefetchReady) onRefetchReady(refetch)
  }, [refetch, onRefetchReady])

  const rowIdsOnPage = useMemo(
    () =>
      apiRows
        .map((r: unknown) => String((r as { $id?: string }).$id ?? ''))
        .filter(Boolean),
    [apiRows],
  )

  useEffect(() => {
    if (rowIdsOnPage.length === 0) {
      setPreviewRowId(null)
      return
    }
    setPreviewRowId((prev) => {
      if (prev && rowIdsOnPage.includes(prev)) return prev
      if (prev && !rowIdsOnPage.includes(prev)) return null
      if (!prev) {
        if (skipNextAutoPreviewRef.current) {
          skipNextAutoPreviewRef.current = false
          return null
        }
        return rowIdsOnPage[0] ?? null
      }
      return prev
    })
  }, [rowIdsOnPage])

  const previewRow = useMemo(() => {
    if (!previewRowId) return null
    const raw = apiRows.find(
      (r: unknown) => String((r as { $id?: string }).$id ?? '') === previewRowId,
    )
    return raw ? (raw as Record<string, unknown>) : null
  }, [apiRows, previewRowId])

  const bulkDeleteMutation = useMutation({
    mutationFn: async (rowIds: string[]) => {
      await Promise.all(
        rowIds.map((rowId) =>
          deleteProjectTableRow(projectId, databaseId, dbKind, tableId, rowId),
        ),
      )
    },
    onSuccess: async (_data, rowIds) => {
      await queryClient.refetchQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(
        `${t('Successfully deleted')} ${selectedRows.size} ${
          selectedRows.size === 1
            ? dbLabels.recordSingular
            : dbLabels.recordPlural
        }`,
      )
      const pid = previewRowId
      if (pid && rowIds.includes(pid)) {
        skipNextAutoPreviewRef.current = true
        setPreviewRowId(null)
      }
      setSelectedRows(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete documents'))
    },
  })

  const toggleRow = (id: string) => {
    const next = new Set(selectedRows)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setSelectedRows(next)
  }

  const toggleAll = () => {
    if (selectedRows.size === apiRows.length) {
      setSelectedRows(new Set())
    } else {
      setSelectedRows(new Set(rowIdsOnPage))
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedRows(new Set())
    onNavigateToList({ page })
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedRows(new Set())
    onNavigateToList({ page: 1, limit: newPageSize })
  }

  if (showRowsLoading) {
    return (
      <div className="flex flex-1 items-center justify-center p-8">
        <p className="text-[13px] text-muted-foreground">{t('Loading documents…')}</p>
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      {apiRows.length === 0 ? (
        <>
          <div className="flex min-h-0 flex-1 flex-col items-center justify-center border-t border-border px-6 py-12">
            <EmptyState
              icon={FileText}
              title={
                documentsPaneHasFilters
                  ? dbLabels.noRecordsFilteredTitle
                  : dbLabels.noRecordsYetTitle
              }
              description={
                documentsPaneHasFilters
                  ? t('Try adjusting or clearing filters.')
                  : dbLabels.addFirstRecordHint
              }
              isEmpty={!documentsPaneHasFilters}
              hasFilters={documentsPaneHasFilters}
              variant="centered"
              iconSize="md"
            />
          </div>
          <div className="h-[54px] shrink-0 border-t border-border bg-background">
            <div className="flex h-full items-center px-4">
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedRowsTotal ?? rowsTotal}
                pageSize={effectivePageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="documents"
                className="h-full min-h-0 border-0 mt-0 py-0"
              />
            </div>
          </div>
        </>
      ) : (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden md:flex-row">
          <div className="flex max-h-[min(40dvh,300px)] min-h-0 w-full shrink-0 flex-col border-b border-border md:max-h-none md:w-[min(420px,42%)] md:min-w-[260px] md:border-b-0 md:border-e">
            <div className="min-h-0 min-w-0 flex-1 overflow-auto">
              <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="w-[40px] px-4 text-center">
                    <div className="flex justify-center">
                      <Checkbox
                        checked={
                          apiRows.length > 0 &&
                          selectedRows.size === apiRows.length
                        }
                        onCheckedChange={toggleAll}
                      />
                    </div>
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Document ID')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Created')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Updated')}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {apiRows.map((raw: unknown) => {
                  const row = raw as Record<string, unknown>
                  const id = String(row.$id ?? '')
                  const isPreview = id === previewRowId
                  return (
                    <TableRow
                      key={id}
                      className={cn(
                        'cursor-pointer',
                        isPreview
                          ? 'bg-muted/25 ring-1 ring-inset ring-border/20 hover:bg-muted/35'
                          : undefined,
                      )}
                      onClick={() => setPreviewRowId(id)}
                    >
                      <TableCell
                        className="px-4 py-3 text-center"
                        onClick={(e) => e.stopPropagation()}
                      >
                        <div className="flex justify-center">
                          <Checkbox
                            checked={selectedRows.has(id)}
                            onCheckedChange={() => toggleRow(id)}
                            onClick={(e) => e.stopPropagation()}
                          />
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <CopyableId id={id} size="xs" />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {row.$createdAt ? (
                          <DateTooltip
                            date={new Date(String(row.$createdAt))}
                            className="text-[12px] text-muted-foreground"
                          />
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                             - 
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {row.$updatedAt ? (
                          <DateTooltip
                            date={new Date(String(row.$updatedAt))}
                            className="text-[12px] text-muted-foreground"
                          />
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

            <div className="h-[54px] shrink-0 border-t border-border bg-background">
              <div className="flex h-full items-center px-4">
                <Pagination
                  currentPage={displayedPage}
                  totalItems={displayedRowsTotal ?? rowsTotal}
                  pageSize={effectivePageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={handlePageChange}
                  onPageSizeChange={handlePageSizeChange}
                  itemLabel="documents"
                  className="h-full min-h-0 border-0 mt-0 py-0"
                />
              </div>
            </div>
          </div>

          <div className="flex min-h-[min(36dvh,240px)] min-w-0 flex-1 flex-col overflow-hidden bg-muted/20 md:min-h-0">
            {previewRow ? (
              <pre className="min-h-0 flex-1 overflow-auto overflow-x-auto p-3 font-mono text-[11px] leading-relaxed text-foreground sm:p-4 sm:text-[12px]">
                {documentPayloadJson(previewRow)}
              </pre>
            ) : (
              <div className="flex h-full min-h-0 flex-col items-center justify-center gap-2 px-6 text-center">
                <FileText className="h-10 w-10 text-muted-foreground/45" />
                <p className="text-[14px] font-medium text-foreground">
                  {dbLabels.noRecordSelectedTitle}
                </p>
                <p className="max-w-sm text-[13px] text-muted-foreground">
                  {dbLabels.noRecordSelectedHint}
                </p>
              </div>
            )}
          </div>
        </div>
      )}

      {selectedRows.size > 0 && canWriteRows && (
        <div className="fixed bottom-4 start-1/2 z-50 w-[min(100%,calc(100vw-2rem))] max-w-md -translate-x-1/2 px-2 sm:w-auto sm:max-w-none sm:px-0">
          <div className="mx-auto flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border bg-background px-4 py-3 shadow-lg sm:min-w-[400px] sm:gap-3 sm:px-6">
            <Badge variant="info" className="h-6 px-2.5">
              {selectedRows.size === 1
                ? dbLabels.oneRecordSelectedLabel
                : `${selectedRows.size} ${dbLabels.recordsSelectedSuffix}`}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedRows(new Set())}
                className="h-8 text-xs"
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={() => setDeleteDialogOpen(true)}
                className="h-8 gap-2"
              >
                {t('Delete')}
              </Button>
            </div>
          </div>
        </div>
      )}

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete documents')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete {selectedRows.size} document
              {selectedRows.size > 1 ? 's' : ''}? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={bulkDeleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() =>
                bulkDeleteMutation.mutate(Array.from(selectedRows))
              }
              disabled={bulkDeleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
