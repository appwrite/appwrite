import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams, useLocation, useSearch } from '@tanstack/react-router'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import {
  usePostgresTableRows,
} from '@/lib/react-query/hooks'
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
import { PostgresRowsEditSessionProvider } from './_components/PostgresRowsEditSession'
import { PostgresRowEditDrawer } from './_components/PostgresRowEditDrawer'
import { PostgresTableRowsEmptyState } from './_components/PostgresTableRowsEmptyState'
import {
  buildPostgresRowIdentityFromRow,
  POSTGRES_ROW_CTID_COLUMN,
} from '@/lib/postgres-row-sql'
import type { PostgresRowIdentity } from '@/lib/postgres-row-sql'
import { postgresNav } from '@/lib/postgres-database-routes'
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
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const canWrite = canShowTableSecuritySettings(access, features)

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

  useEffect(() => {
    setPageSize(ROWS_DEFAULT_PAGE_SIZE)
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [tableId])

  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [urlSearch, filterKeys])

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

  const handlePageChange = useCallback((page: number) => {
    setRequestedPage(page)
  }, [])

  const handlePageSizeChange = useCallback((limit: number) => {
    setPageSize(limit)
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [])

  const handlePaginationInteract = useCallback(() => {
    setDrawerOpen(false)
  }, [])

  const openCreateRow = useCallback(() => {
    setDrawerRow(null)
    setDrawerIdentity(null)
    setDrawerFocusedField(undefined)
    setDrawerOpen(true)
  }, [])

  const openRowInDrawer = useCallback(
    (row: Record<string, unknown>, focusedField?: string) => {
      setDrawerRow(row)
      setDrawerIdentity(buildPostgresRowIdentityFromRow(row, tableColumns))
      setDrawerFocusedField(focusedField)
      setDrawerOpen(true)
    },
    [tableColumns],
  )

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
    createDisabledTooltip: canWrite
      ? undefined
      : t("You don't have permission to modify rows."),
    showRefresh: true,
    onRefresh: handleRefresh,
    isRefreshing: isFetching,
    filterTrigger,
  })

  const displayedRows = useMemo(
    () =>
      rows.map((row) => {
        const copy = { ...row }
        delete copy[POSTGRES_ROW_CTID_COLUMN]
        return copy
      }),
    [rows],
  )

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
          rows={displayedRows}
          rowNumberOffset={(displayedPage - 1) * pageSize}
          canWrite={canWrite}
          isLoading={isLoading && rows.length === 0}
          emptyContent={emptyContent}
          onOpenRow={openRowInDrawer}
          currentPage={displayedPage}
          totalItems={requestedFetching ? total : (requestedTotal ?? total)}
          pageSize={pageSize}
          onPageChange={handlePageChange}
          onPageSizeChange={handlePageSizeChange}
          onPaginationInteract={handlePaginationInteract}
        />
      </div>

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
