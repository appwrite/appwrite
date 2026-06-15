import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams, useLocation, useSearch } from '@tanstack/react-router'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import {
  usePostgresTableColumns,
  usePostgresTableIndexes,
  usePostgresTableRows,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  buildListSearchParams,
  getLimit,
  getPage,
  getQueryParam,
  getSearch,
  mapToQueryParam,
  postgresRowsFilterColumns,
  queryParamToMap,
  urlFromRouterLocation,
  type CompactFilterKey,
} from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { Pagination } from '@/components/global/shared/Pagination'
import { PostgresTableHeader } from './_components/PostgresTableHeader'
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

export type PostgresTableRowsViewProps = {
  databaseId: string
  tableId: string
}

export function PostgresTableRowsView({
  databaseId,
  tableId,
}: PostgresTableRowsViewProps) {
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
  const urlPage = getPage(rowsListUrl, 1)
  const urlLimit = getLimit(rowsListUrl, ROWS_DEFAULT_PAGE_SIZE)
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
  const [requestedPage, setRequestedPage] = useState(urlPage)
  const [displayedPage, setDisplayedPage] = useState(urlPage)
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
    setRequestedPage(urlPage)
    setDisplayedPage(urlPage)
  }, [tableId, urlPage, urlLimit, urlSearch, filterKeys])

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
    urlLimit,
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
    urlLimit,
    rowsListParams,
  )

  useEffect(() => {
    if (!requestedFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [displayedPage, requestedFetching, requestedPage])

  const { total: columnCount } = usePostgresTableColumns(
    projectId,
    databaseId,
    tableId,
  )
  const { total: indexCount } = usePostgresTableIndexes(
    projectId,
    databaseId,
    tableId,
  )

  const filterColumns = useMemo(
    () => postgresRowsFilterColumns(tableColumns),
    [tableColumns],
  )
  const filterScope = `postgres.rows.${databaseId}.${tableId}`

  const navigateToRowsList = useCallback(
    (updates: {
      search?: string
      page?: number
      limit?: number
      query?: string
    }) => {
      const nextSearch = buildListSearchParams({
        search:
          updates.search !== undefined ? updates.search : urlSearch ?? '',
        page: updates.page ?? displayedPage,
        limit: updates.limit ?? urlLimit,
        query: updates.query,
      })
      navigate({
        ...postgresNav({ projectId, databaseId }).table({ tableId }).rows(),
        search: nextSearch,
        replace: true,
      })
    },
    [databaseId, displayedPage, navigate, projectId, tableId, urlLimit, urlSearch],
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
        page: 1,
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
        page: 1,
      })
    },
    [filterMap, navigateToRowsList],
  )

  const handleClearAllFilters = useCallback(() => {
    navigateToRowsList({ query: '', page: 1 })
  }, [navigateToRowsList])

  const handleSearchChange = useCallback(
    (value: string) => {
      navigateToRowsList({ search: value, page: 1 })
    },
    [navigateToRowsList],
  )

  const handlePageChange = useCallback(
    (page: number) => {
      setRequestedPage(page)
      navigateToRowsList({ page })
    },
    [navigateToRowsList],
  )

  const handlePageSizeChange = useCallback(
    (limit: number) => {
      setRequestedPage(1)
      setDisplayedPage(1)
      navigateToRowsList({ page: 1, limit })
    },
    [navigateToRowsList],
  )

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
        No records match your filters
      </p>
      <p className="mt-1 text-[13px] text-muted-foreground">
        Try adjusting or clearing filters.
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
      <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        <div className="shrink-0 bg-background">
          <PostgresTableHeader
            projectId={projectId}
            databaseId={databaseId}
            tableId={tableId}
            activeTab="rows"
            columnCount={columnCount}
            indexCount={indexCount}
            searchPlaceholder="Search rows..."
            searchValue={urlSearch ?? ''}
            onSearchChange={handleSearchChange}
            createLabel={canWrite ? 'Create row' : undefined}
            onCreate={canWrite ? openCreateRow : undefined}
            createDisabled={!canWrite}
            createDisabledTooltip={
              canWrite
                ? undefined
                : "You don't have permission to modify rows."
            }
            showRefresh
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            filterTrigger={
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
            }
          />
        </div>

        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <PostgresRowsSpreadsheet
            tableId={tableId}
            columns={tableColumns}
            rows={displayedRows}
            rowNumberOffset={(displayedPage - 1) * urlLimit}
            canWrite={canWrite}
            isLoading={isLoading && rows.length === 0}
            emptyContent={emptyContent}
            onOpenRow={openRowInDrawer}
          />
        </div>

        {total > 0 ? (
          <div className="shrink-0 border-t border-border bg-background px-4 py-3 sm:px-6">
            <Pagination
              currentPage={displayedPage}
              totalItems={requestedFetching ? total : (requestedTotal ?? total)}
              pageSize={urlLimit}
              onPageChange={handlePageChange}
              onPageSizeChange={handlePageSizeChange}
              pageSizeOptions={[10, 25, 50, 100]}
            />
          </div>
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
      </div>
    </PostgresRowsEditSessionProvider>
  )
}
