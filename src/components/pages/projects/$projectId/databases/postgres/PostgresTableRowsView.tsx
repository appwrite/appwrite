import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams, useLocation, useSearch } from '@tanstack/react-router'
import { toast } from 'sonner'
import { useDatabaseTableOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import {
  useDeletePostgresTableRows,
  usePostgresTableRows,
} from '@/lib/react-query/hooks'
import { useProject } from '@/lib/react-query/hooks/projects'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  buildListSearchParams,
  getQueryParam,
  getSearch,
  mapToQueryParam,
  postgresRowsFilterColumns,
  queryParamToMap,
  urlFromRouterLocation,
  type CompactFilterKey,
} from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { usePostgresTableHeaderSlot } from './_components/PostgresTableHeaderSlotContext'
import { PostgresRowsSpreadsheet } from './_components/PostgresRowsSpreadsheet'
import {
  PostgresRowsEditSessionProvider,
  usePostgresRowsEditSession,
} from './_components/PostgresRowsEditSession'
import { PostgresRowEditDrawer } from './_components/PostgresRowEditDrawer'
import { PostgresTableRowsEmptyState } from './_components/PostgresTableRowsEmptyState'
import {
  buildPostgresRowIdentityFromRow,
  getPostgresRowKey,
  POSTGRES_ROW_CTID_COLUMN,
} from '@/lib/postgres-row-sql'
import type { PostgresRowIdentity } from '@/lib/postgres-row-sql'
import { postgresNav } from '@/lib/postgres-database-routes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'

export type PostgresTableRowsViewProps = {
  databaseId: string
  tableId: string
}

export function PostgresTableRowsView({
  databaseId,
  tableId,
}: PostgresTableRowsViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const location = useLocation()
  const routeSearch = useSearch({ strict: false }) as Record<string, unknown> | undefined
  const { project } = useProject(projectId)

  const { canWrite, writeTooltip } = useDatabaseTableOperationsAccess({
    permissionDeniedTooltip: t("You don't have permission to modify rows."),
  })

  const rowsListUrl = useMemo(
    () => urlFromRouterLocation(location, window.location.origin),
    [location.pathname, location.search],
  )

  const urlSearch =
    getSearch(rowsListUrl)?.trim() ||
    (routeSearch?.search as string | undefined)?.trim() ||
    undefined
  const filterMap = useMemo(
    () =>
      queryParamToMap(
        getQueryParam(rowsListUrl) ??
          (routeSearch?.query as string | undefined) ??
          null,
      ),
    [rowsListUrl, routeSearch?.query],
  )
  const filterKeys = useMemo(
    () => (filterMap.size > 0 ? Array.from(filterMap.keys()) : undefined),
    [filterMap],
  )
  const hasActiveFilters = filterMap.size > 0 || Boolean(urlSearch)

  const [rowsFiltersOpen, setRowsFiltersOpen] = useState(false)
  const [pageSize, setPageSize] = useState(ROWS_DEFAULT_PAGE_SIZE)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const strippedPaginationFromUrlRef = useRef(false)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [drawerRow, setDrawerRow] = useState<Record<string, unknown> | null>(
    null,
  )
  const [drawerIdentity, setDrawerIdentity] =
    useState<PostgresRowIdentity | null>(null)
  const [drawerFocusedField, setDrawerFocusedField] = useState<
    string | undefined
  >()
  const [selectedRows, setSelectedRows] = useState<
    Map<string, PostgresRowIdentity>
  >(() => new Map())
  const [bulkDeleteDialogOpen, setBulkDeleteDialogOpen] = useState(false)

  const clearSelection = useCallback(() => {
    setSelectedRows(new Map())
    setBulkDeleteDialogOpen(false)
  }, [])

  useEffect(() => {
    setPageSize(ROWS_DEFAULT_PAGE_SIZE)
    setRequestedPage(1)
    setDisplayedPage(1)
    clearSelection()
  }, [tableId, clearSelection])

  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
    clearSelection()
  }, [urlSearch, filterKeys, clearSelection])

  useEffect(() => {
    if (strippedPaginationFromUrlRef.current) return
    if (routeSearch?.page == null && routeSearch?.limit == null) return
    strippedPaginationFromUrlRef.current = true
    const query =
      getQueryParam(rowsListUrl) ??
      (routeSearch?.query as string | undefined) ??
      undefined
    navigate({
      ...postgresNav({ projectId, databaseId }).table({ tableId }).rows(),
      search: buildListSearchParams({
        search: urlSearch ?? '',
        query: query || undefined,
      }),
      replace: true,
    })
  }, [
    databaseId,
    navigate,
    projectId,
    routeSearch?.limit,
    routeSearch?.page,
    routeSearch?.query,
    rowsListUrl,
    tableId,
    urlSearch,
  ])

  const rowsListParams = useMemo(
    () => ({
      search: urlSearch,
      filterKeys,
    }),
    [filterKeys, urlSearch],
  )

  const {
    total: requestedTotal,
    isFetching: requestedFetching,
  } = usePostgresTableRows(
    projectId,
    databaseId,
    tableId,
    requestedPage - 1,
    pageSize,
    rowsListParams,
  )

  const {
    rows,
    total,
    tableColumns,
    isLoading,
    isFetching,
    refetch,
  } = usePostgresTableRows(
    projectId,
    databaseId,
    tableId,
    displayedPage - 1,
    pageSize,
    rowsListParams,
  )

  useEffect(() => {
    if (!requestedFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [displayedPage, requestedFetching, requestedPage])

  const navigateToRowsList = useCallback(
    (updates: { search?: string; query?: string }) => {
      const nextSearch = buildListSearchParams({
        search:
          updates.search !== undefined ? updates.search : urlSearch ?? '',
        query: updates.query,
      })
      navigate({
        ...postgresNav({ projectId, databaseId }).table({ tableId }).rows(),
        search: nextSearch,
        replace: true,
      })
    },
    [databaseId, navigate, projectId, tableId, urlSearch],
  )

  const handleApplyFilter = useCallback(
    (
      compactKey: CompactFilterKey,
      _queryString: string,
      replaceKey?: CompactFilterKey,
    ) => {
      const nextMap = new Map(filterMap)
      if (replaceKey) {
        for (const key of nextMap.keys()) {
          if (
            key.c === replaceKey.c &&
            key.o === replaceKey.o &&
            JSON.stringify(key.v) === JSON.stringify(replaceKey.v)
          ) {
            nextMap.delete(key)
          }
        }
      }
      nextMap.set(compactKey, '')
      navigateToRowsList({
        query: mapToQueryParam(nextMap),
      })
    },
    [filterMap, navigateToRowsList],
  )

  const handleRemoveFilter = useCallback(
    (compactKey: CompactFilterKey) => {
      const nextMap = new Map(filterMap)
      nextMap.delete(compactKey)
      navigateToRowsList({
        query: mapToQueryParam(nextMap),
      })
    },
    [filterMap, navigateToRowsList],
  )

  const handleClearAllFilters = useCallback(() => {
    navigateToRowsList({ query: '' })
  }, [navigateToRowsList])

  const handleSearchChange = useCallback(
    (value: string) => {
      navigateToRowsList({ search: value })
    },
    [navigateToRowsList],
  )

  const handlePageChange = useCallback(
    (page: number) => {
      setRequestedPage(page)
      clearSelection()
    },
    [clearSelection],
  )

  const handlePageSizeChange = useCallback(
    (limit: number) => {
      setPageSize(limit)
      setRequestedPage(1)
      setDisplayedPage(1)
      clearSelection()
    },
    [clearSelection],
  )

  const handlePaginationInteract = useCallback(() => {
    setDrawerOpen(false)
  }, [])

  const handleToggleRow = useCallback(
    (rowKey: string, identity: PostgresRowIdentity) => {
      setSelectedRows((prev) => {
        const next = new Map(prev)
        if (next.has(rowKey)) next.delete(rowKey)
        else next.set(rowKey, identity)
        return next
      })
    },
    [],
  )

  const handleToggleAllRows = useCallback(
    (
      pageRows: Array<{ rowKey: string; identity: PostgresRowIdentity }>,
      selectAll: boolean,
    ) => {
      setSelectedRows((prev) => {
        const next = new Map(prev)
        if (selectAll) {
          for (const { rowKey, identity } of pageRows) {
            next.set(rowKey, identity)
          }
        } else {
          for (const { rowKey } of pageRows) {
            next.delete(rowKey)
          }
        }
        return next
      })
    },
    [],
  )

  const openCreateRow = useCallback(() => {
    setDrawerRow(null)
    setDrawerIdentity(null)
    setDrawerFocusedField(undefined)
    setDrawerOpen(true)
  }, [])

  const openRowInDrawer = useCallback(
    (row: Record<string, unknown>, focusedField?: string) => {
      const identity = buildPostgresRowIdentityFromRow(row, tableColumns)
      const rowForDrawer = { ...row }
      delete rowForDrawer[POSTGRES_ROW_CTID_COLUMN]
      setDrawerRow(rowForDrawer)
      setDrawerIdentity(identity)
      setDrawerFocusedField(focusedField)
      setDrawerOpen(true)
    },
    [tableColumns],
  )

  const drawerRowKey = useMemo(() => {
    if (!drawerRow) return null
    // Rebuild key from identity when possible so ctid-backed rows still match.
    if (drawerIdentity?.primaryKeyValues) {
      return JSON.stringify(drawerIdentity.primaryKeyValues)
    }
    if (drawerIdentity?.ctid) return `ctid:${drawerIdentity.ctid}`
    return getPostgresRowKey(drawerRow, tableColumns)
  }, [drawerIdentity, drawerRow, tableColumns])

  const filterColumns = useMemo(
    () => postgresRowsFilterColumns(tableColumns),
    [tableColumns],
  )
  const filterScope = `postgres.rows.${databaseId}.${tableId}`

  const filterTrigger = useMemo(
    () => (
      <FiltersPopover
        open={rowsFiltersOpen}
        onOpenChange={setRowsFiltersOpen}
        columns={filterColumns}
        filterMap={filterMap}
        onApplyFilter={handleApplyFilter}
        onRemoveFilter={handleRemoveFilter}
        onClearAll={handleClearAllFilters}
        filterScope={filterScope}
        resourceLabel="rows"
        teamId={project?.teamId}
      />
    ),
    [
      filterColumns,
      filterMap,
      filterScope,
      handleApplyFilter,
      handleClearAllFilters,
      handleRemoveFilter,
      project?.teamId,
      rowsFiltersOpen,
    ],
  )

  const handleRefresh = useCallback(() => {
    void refetch()
  }, [refetch])

  const rowsTotal = requestedTotal ?? total
  const showRowsSearch = hasActiveFilters || rowsTotal > 0

  usePostgresTableHeaderSlot({
    searchPlaceholder: showRowsSearch ? 'Search rows...' : undefined,
    searchValue: showRowsSearch ? urlSearch ?? '' : undefined,
    onSearchChange: showRowsSearch ? handleSearchChange : undefined,
    createLabel: canWrite ? t('Create row') : undefined,
    onCreate: canWrite ? openCreateRow : undefined,
    createDisabled: !canWrite,
    createDisabledTooltip: writeTooltip,
    showRefresh: true,
    onRefresh: handleRefresh,
    isRefreshing: isFetching,
    filterTrigger,
  })

  const emptyContent = hasActiveFilters ? (
    <div className="text-center">
      <p className="text-[13px] font-medium text-foreground">
        {t('No records match your filters')}
      </p>
      <p className="mt-1 text-[13px] text-muted-foreground">
        {t('Try adjusting or clearing filters.')}
      </p>
    </div>
  ) : (
    <PostgresTableRowsEmptyState />
  )

  const selectedRowKeys = useMemo(
    () => new Set(selectedRows.keys()),
    [selectedRows],
  )

  return (
    <PostgresRowsEditSessionProvider
      projectId={projectId}
      databaseId={databaseId}
      tableId={tableId}
      canWrite={canWrite}
    >
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <PostgresRowsSpreadsheet
          databaseId={databaseId}
          tableId={tableId}
          columns={tableColumns}
          rows={rows}
          rowNumberOffset={(displayedPage - 1) * pageSize}
          canWrite={canWrite}
          isLoading={isLoading && rows.length === 0}
          emptyContent={emptyContent}
          onOpenRow={openRowInDrawer}
          selectedRowKeys={canWrite ? selectedRowKeys : undefined}
          onToggleRow={canWrite ? handleToggleRow : undefined}
          onToggleAllRows={canWrite ? handleToggleAllRows : undefined}
          currentPage={displayedPage}
          totalItems={requestedFetching ? total : (requestedTotal ?? total)}
          pageSize={pageSize}
          onPageChange={handlePageChange}
          onPageSizeChange={handlePageSizeChange}
          onPaginationInteract={handlePaginationInteract}
        />
      </div>

      {canWrite ? (
        <PostgresRowsBulkDelete
          projectId={projectId}
          databaseId={databaseId}
          tableId={tableId}
          selectedRows={selectedRows}
          dialogOpen={bulkDeleteDialogOpen}
          onDialogOpenChange={setBulkDeleteDialogOpen}
          onClearSelection={clearSelection}
          onDeleted={(rowKeys) => {
            if (drawerRowKey && rowKeys.includes(drawerRowKey)) {
              setDrawerOpen(false)
              setDrawerRow(null)
              setDrawerIdentity(null)
            }
          }}
        />
      ) : null}

      <PostgresRowEditDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        projectId={projectId}
        databaseId={databaseId}
        tableId={tableId}
        row={drawerRow}
        identity={drawerIdentity}
        columns={tableColumns}
        focusedField={drawerFocusedField}
        canWrite={canWrite}
      />
    </PostgresRowsEditSessionProvider>
  )
}

type PostgresRowsBulkDeleteProps = {
  projectId: string
  databaseId: string
  tableId: string
  selectedRows: Map<string, PostgresRowIdentity>
  dialogOpen: boolean
  onDialogOpenChange: (open: boolean) => void
  onClearSelection: () => void
  onDeleted: (rowKeys: string[]) => void
}

function PostgresRowsBulkDelete({
  projectId,
  databaseId,
  tableId,
  selectedRows,
  dialogOpen,
  onDialogOpenChange,
  onClearSelection,
  onDeleted,
}: PostgresRowsBulkDeleteProps) {
  const t = useT()
  const editSession = usePostgresRowsEditSession()
  const deleteMutation = useDeletePostgresTableRows(
    projectId,
    databaseId,
    tableId,
  )
  const selectedCount = selectedRows.size

  const handleConfirmDelete = () => {
    if (selectedCount === 0) return
    const rowKeys = Array.from(selectedRows.keys())
    const identities = Array.from(selectedRows.values())
    deleteMutation.mutate(identities, {
      onSuccess: () => {
        editSession?.discardRows(rowKeys)
        onDeleted(rowKeys)
        onClearSelection()
        onDialogOpenChange(false)
        toast.success(
          `${t('Successfully deleted')} ${selectedCount} ${
            selectedCount === 1 ? t('row') : t('rows')
          }`,
        )
      },
      onError: (error) => {
        toast.error(getErrorMessage(error) ?? t('Failed to delete rows'))
      },
    })
  }

  if (selectedCount === 0) return null

  return (
    <>
      <div className="fixed bottom-4 start-1/2 z-50 w-[min(100%,calc(100vw-2rem))] max-w-md -translate-x-1/2 px-2 sm:px-0 sm:w-auto sm:max-w-none">
        <div className="mx-auto flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border bg-background px-4 py-3 sm:min-w-[400px] sm:gap-3 sm:px-6">
          <Badge variant="secondary" className="h-6 px-2.5">
            {selectedCount === 1
              ? t('1 row selected')
              : `${selectedCount} ${t('rows selected')}`}
          </Badge>
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={onClearSelection}
              className="h-8 text-xs"
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={() => onDialogOpenChange(true)}
              disabled={deleteMutation.isPending}
              className="h-8 gap-2"
            >
              {t('Delete')}
            </Button>
          </div>
        </div>
      </div>

      <Dialog open={dialogOpen} onOpenChange={onDialogOpenChange}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>
              {selectedCount === 1 ? t('Delete row') : t('Delete rows')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')} {selectedCount}{' '}
              {selectedCount === 1 ? t('row') : t('rows')}?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => onDialogOpenChange(false)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
