import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useLocation } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  deleteProjectTableRow,
  useProjectTableRows,
} from '@/lib/react-query/hooks'
import {
  ROWS_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'
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
import { FileJson } from 'lucide-react'

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
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id
  const location = useLocation()
  const queryClient = useQueryClient()

  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set())
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
  const [sortBy, setSortBy] = useState(rowsSortBy)
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>(rowsSortOrder)
  const [displayedSortBy, setDisplayedSortBy] = useState(rowsSortBy)
  const [displayedSortOrder, setDisplayedSortOrder] =
    useState<'asc' | 'desc'>(rowsSortOrder)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

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
  }, [
    location.pathname,
    projectId,
    databaseId,
    tableId,
    rowsUrlPage,
    rowsUrlSearch,
    rowsFilterQueryString,
  ])

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

  const bulkDeleteMutation = useMutation({
    mutationFn: async (rowIds: string[]) => {
      await Promise.all(
        rowIds.map((rowId) =>
          deleteProjectTableRow(projectId, databaseId, tableId, rowId),
        ),
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(
        `Successfully deleted ${selectedRows.size} document${selectedRows.size > 1 ? 's' : ''}`,
      )
      setSelectedRows(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete documents')
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
      setSelectedRows(
        new Set(
          apiRows.map((r: unknown) => (r as { $id: string }).$id).filter(Boolean),
        ),
      )
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
        <p className="text-[13px] text-muted-foreground">Loading documents…</p>
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="min-h-0 flex-1 overflow-auto">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="w-[40px] px-4">
                <Checkbox
                  checked={
                    apiRows.length > 0 && selectedRows.size === apiRows.length
                  }
                  onCheckedChange={toggleAll}
                />
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Document ID
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[280px]">
                <span className="inline-flex items-center gap-1.5">
                  <FileJson className="h-3.5 w-3.5" />
                  JSON
                </span>
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Created
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Updated
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {apiRows.map((raw: unknown) => {
              const row = raw as Record<string, unknown>
              const id = String(row.$id ?? '')
              return (
                <TableRow key={id}>
                  <TableCell className="px-4 py-3">
                    <Checkbox
                      checked={selectedRows.has(id)}
                      onCheckedChange={() => toggleRow(id)}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <CopyableId id={id} size="xs" />
                  </TableCell>
                  <TableCell className="px-4 py-3 align-top">
                    <pre className="max-h-48 overflow-auto rounded-md border border-border bg-muted/40 p-3 text-left font-mono text-[11px] leading-relaxed text-foreground">
                      {documentPayloadJson(row)}
                    </pre>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {row.$createdAt ? (
                      <DateTooltip
                        date={new Date(String(row.$createdAt))}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {row.$updatedAt ? (
                      <DateTooltip
                        date={new Date(String(row.$updatedAt))}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">—</span>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {selectedRows.size > 0 && canWriteRows && (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
          <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3 shadow-lg">
            <Badge variant="info" className="h-6 px-2.5">
              {selectedRows.size} document{selectedRows.size > 1 ? 's' : ''}{' '}
              selected
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedRows(new Set())}
                className="h-8 text-xs"
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={() => setDeleteDialogOpen(true)}
                className="h-8 gap-2"
              >
                Delete
              </Button>
            </div>
          </div>
        </div>
      )}

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

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete documents</DialogTitle>
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
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() =>
                bulkDeleteMutation.mutate(Array.from(selectedRows))
              }
              disabled={bulkDeleteMutation.isPending}
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
