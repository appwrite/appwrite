import { cn } from '@/lib/utils'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import {
  SPREADSHEET_FILLER_CELL_CLASS,
  SPREADSHEET_FILLER_HEADER_CLASS,
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import { Key, Trash2 } from 'lucide-react'
import { useState, useEffect, useRef, useMemo } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  useProjectCollectionAttributes,
  useProjectTableRows,
  createProjectTableIndex,
  deleteProjectTableIndex,
  useProjectCollectionIndexes,
} from '@/lib/react-query/hooks'
import { COLUMNS_INDEXES_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { buildCollectionIndexableAttributes } from '@/lib/databases/collection-indexable-attributes'
import { IndexDrawer, type IndexFormData } from '../documentsdb/Index'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import {
  getPage,
  getLimit,
  queryParamToMap,
  urlFromRouterLocation,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'
import {
  localizeResourceStatusLabel,
  localizeTableIndexTypeLabel,
} from '@/lib/i18n/resource-status-labels'

const DB_KIND = 'vectorsdb' as const satisfies DatabaseRouteKind

interface IndexColumnEntry {
  column: string
  order: 'ASC' | 'DESC' | null
  length: number | null
}

const getIndexTypeColor = (type: string) => {
  const colors: Record<string, string> = {
    key: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    unique:
      'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    fulltext:
      'bg-purple-500/10 text-purple-600 dark:text-purple-400',
  }
  return colors[type] || 'bg-muted text-muted-foreground'
}

const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const bodyCellBorderClass = 'border-b border-e border-border'

const ROWS_TABLE_EDGE_COL_PX = 40
const INDEXES_GRID_MIN_WIDTH_PX =
  200 + 100 + 300 + 100 + ROWS_TABLE_EDGE_COL_PX
const spreadsheetActionsColStyle = {
  width: ROWS_TABLE_EDGE_COL_PX,
  minWidth: ROWS_TABLE_EDGE_COL_PX,
  maxWidth: ROWS_TABLE_EDGE_COL_PX,
}
const stickyActionsHeaderClass = cn(
  'relative sticky end-0 z-30 bg-background p-0',
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
)
const stickyActionsCellBaseClass = cn(
  'sticky end-0 z-10 border-b border-border p-0',
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
)

export type CollectionIndexesSpreadsheetProps = {
  table: Models.Collection | { $id: string; name?: string }
  onCreateReady?: (openDialog: () => void) => void
  canWriteTables?: boolean
  filterMap?: Map<CompactFilterKey, string>
}

export function IndexesSpreadsheet({
  table,
  onCreateReady,
  canWriteTables: _canWriteTables = true,
  filterMap: filterMapProp,
}: CollectionIndexesSpreadsheetProps) {
  const t = useT()
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, DB_KIND)
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const collectionId = table.$id
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as
    | { query?: string; page?: number; limit?: number }
    | undefined
  const indexesFilterMapFromUrl = useMemo(
    () => queryParamToMap(search?.query ?? null),
    [search?.query],
  )
  const indexesFilterMap = filterMapProp ?? indexesFilterMapFromUrl
  const indexesFilterQueries =
    indexesFilterMap.size > 0
      ? Array.from(indexesFilterMap.values())
      : undefined

  const indexesListParams = useMemo(() => {
    const url = urlFromRouterLocation(location, window.location.origin)
    const pageFromSearch =
      search?.page != null
        ? typeof search.page === 'number'
          ? search.page
          : Number(search.page)
        : undefined
    const limitFromSearch =
      search?.limit != null
        ? typeof search.limit === 'number'
          ? search.limit
          : Number(search.limit)
        : undefined
    const page =
      Number.isInteger(pageFromSearch) && (pageFromSearch ?? 0) >= 1
        ? pageFromSearch!
        : getPage(url, 1)
    const limit =
      Number.isInteger(limitFromSearch) && (limitFromSearch ?? 0) >= 1
        ? limitFromSearch!
        : getLimit(url, COLUMNS_INDEXES_DEFAULT_PAGE_SIZE)
    return { page, limit }
  }, [location.pathname, location.search, search?.page, search?.limit])
  const indexesPage = indexesListParams.page
  const indexesLimit = indexesListParams.limit
  const indexesPageIndexed = Math.max(0, indexesPage - 1)

  const [indexDialogOpen, setIndexDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [indexToDelete, setIndexToDelete] = useState<string | null>(null)

  const queryClient = useQueryClient()

  const {
    indexes: apiIndexes,
    total: indexesTotal,
    isLoading: indexesLoading,
    isFetching: indexesFetching,
  } = useProjectCollectionIndexes(
    projectId,
    databaseId,
    DB_KIND,
    collectionId,
    indexesFilterQueries,
    indexesPageIndexed,
    indexesLimit,
  )

  const navigateIndexesList = (updates: { page?: number; limit?: number }) => {
    navigate({
      search: ((prev: Record<string, unknown>) => {
        const next = {
          ...(typeof prev === 'object' && prev !== null ? prev : {}),
          ...(updates.page != null && { page: updates.page }),
          ...(updates.limit != null && { limit: updates.limit }),
        }
        return next
      }) as never,
      replace: true,
    })
  }

  const { columns: schemaAttributes } = useProjectCollectionAttributes(
    projectId,
    databaseId,
    DB_KIND,
    collectionId,
  )

  const { rows: sampleDocumentRows } = useProjectTableRows(
    projectId,
    databaseId,
    collectionId,
    DB_KIND,
    0,
    50,
  )

  const availableAttributes = useMemo(
    () =>
      buildCollectionIndexableAttributes(
        schemaAttributes,
        sampleDocumentRows as Record<string, unknown>[],
      ),
    [schemaAttributes, sampleDocumentRows],
  )

  const createIndexMutation = useMutation({
    mutationFn: async (data: IndexFormData) => {
      const apiData: Record<string, unknown> = {
        key: data.key,
        type: data.type,
        columns: data.columns.map((c: IndexColumnEntry) => c.column),
      }

      const allOrders = data.columns.map((c: IndexColumnEntry) => c.order)
      if (allOrders.some((o) => o !== null)) {
        apiData.orders = allOrders
      }

      if (data.type === 'key') {
        const allLengths = data.columns.map((c: IndexColumnEntry) => c.length)
        if (allLengths.some((l) => l !== null && l > 0)) {
          apiData.lengths = allLengths
        }
      }

      return await createProjectTableIndex(
        projectId,
        databaseId,
        DB_KIND,
        collectionId,
        apiData,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, collectionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setIndexDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create index'))
    },
  })

  const deleteIndexMutation = useMutation({
    mutationFn: async (indexKey: string) => {
      return await deleteProjectTableIndex(
        projectId,
        databaseId,
        DB_KIND,
        collectionId,
        indexKey,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, collectionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setIndexToDelete(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete index'))
    },
  })

  const handleCreateIndex = () => {
    setIndexDialogOpen(true)
  }

  const handleDeleteIndex = (indexKey: string) => {
    setIndexToDelete(indexKey)
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleConfirmDelete = () => {
    if (indexToDelete) {
      const key = indexToDelete
      closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
      deleteIndexMutation.mutate(key)
    }
  }

  useEffect(() => {
    if (onCreateReady) {
      onCreateReady(handleCreateIndex)
    }
  }, [onCreateReady])

  const clearAllIndexesFilters = () => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: undefined,
      }),
      replace: true,
    })
  }

  const mappedIndexes = apiIndexes.map((idx: Record<string, unknown>) => ({
    key: idx.key as string,
    type: idx.type as string,
    columns: (idx.columns as string[]) || [],
    orders: (idx.orders as string[]) || [],
    lengths: (idx.lengths as number[]) || [],
    status: (idx.status as string) || 'available',
    $id: idx.$id as string | undefined,
  }))

  const lastDisplayIndexesRef = useRef<typeof mappedIndexes>([])
  useEffect(() => {
    if (!indexesFetching && mappedIndexes.length > 0) {
      lastDisplayIndexesRef.current = mappedIndexes
    }
  }, [indexesFetching, mappedIndexes])
  const indexesToShow =
    indexesFilterMap.size > 0 &&
    indexesFetching &&
    lastDisplayIndexesRef.current.length > 0
      ? lastDisplayIndexesRef.current
      : mappedIndexes

  const showInitialLoading =
    indexesLoading && lastDisplayIndexesRef.current.length === 0

  const indexesFullyEmpty =
    !indexesFetching &&
    apiIndexes.length === 0 &&
    indexesFilterMap.size === 0

  const indexesFilteredEmpty =
    !indexesFetching && apiIndexes.length === 0 && indexesFilterMap.size > 0

  return (
    <div className="flex h-full flex-col relative">
      {showInitialLoading ? (
        <div className="flex flex-1 items-center justify-center">
          <div className="text-muted-foreground">{t('Loading indexes…')}</div>
        </div>
      ) : indexesFullyEmpty ? (
        <div className="flex flex-1 items-center justify-center px-6 py-12">
          <EmptyState
            icon={Key}
            title={dbLabels.noIndexesYetTitle}
            description={dbLabels.addFirstIndexHint}
            isEmpty
            variant="centered"
            iconSize="md"
          />
        </div>
      ) : indexesFilteredEmpty ? (
        <div className="flex flex-1 items-center justify-center px-6 py-12">
          <EmptyState
            icon={Key}
            title={t('No indexes match your filters')}
            description={t(
              'Try adjusting or clearing filters to see more results',
            )}
            hasFilters
            variant="centered"
            iconSize="md"
            action={
              <Button
                variant="outline"
                size="sm"
                onClick={clearAllIndexesFilters}
              >
                {t('Clear filters')}
              </Button>
            }
          />
        </div>
      ) : (
        <>
          <div className="flex-1 overflow-auto overscroll-contain">
            <div
              className={SPREADSHEET_SCROLL_LAYER_CLASS}
              style={{ minWidth: INDEXES_GRID_MIN_WIDTH_PX }}
            >
            <table className="w-full border-collapse">
              <thead className={stickyTheadClass}>
                <tr>
                  <th
                    className={cn(
                      'min-w-[200px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Key')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[100px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Type')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[300px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Attributes')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[100px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Status')}
                    </span>
                  </th>
                  <th aria-hidden className={SPREADSHEET_FILLER_HEADER_CLASS} />
                  <th
                    className={stickyActionsHeaderClass}
                    style={spreadsheetActionsColStyle}
                  />
                </tr>
              </thead>
              <tbody>
                {indexesToShow.map((index) => {
                  const isSystem = index.key?.startsWith('_key_')
                  return (
                    <tr
                      key={index.key || 'unnamed'}
                      className={cn(
                        'group transition-colors hover:bg-muted/50',
                        isSystem && 'bg-muted/30',
                      )}
                    >
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        <div className="flex items-center gap-2">
                          <Key className="h-3.5 w-3.5 text-muted-foreground" />
                          <code
                            className={cn(
                              'font-mono text-[12px]',
                              isSystem
                                ? 'text-muted-foreground'
                                : 'text-foreground',
                            )}
                          >
                            {index.key || 'unnamed'}
                          </code>
                        </div>
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        <Badge
                          variant="outline"
                          className={cn(
                            'text-[11px] font-medium border',
                            getIndexTypeColor(index.type),
                          )}
                        >
                          {localizeTableIndexTypeLabel(index.type, t)}
                        </Badge>
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        <div className="flex flex-wrap gap-2">
                          {index.columns.map((col: string, i: number) => {
                            const order = index.orders?.[i]
                            const length = index.lengths?.[i]
                            const hasLength =
                              length != null && Number(length) > 0
                            return (
                              <div key={i} className="flex items-center gap-1">
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                                  {col}
                                </code>
                                {order && (
                                  <span className="text-[10px] text-muted-foreground/70">
                                    {order}
                                  </span>
                                )}
                                {hasLength && (
                                  <span className="text-[10px] text-muted-foreground/70">
                                    ({length})
                                  </span>
                                )}
                              </div>
                            )
                          })}
                        </div>
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        <Badge
                          variant={
                            index.status === 'available'
                              ? 'success'
                              : 'processing'
                          }
                          className="text-[11px] font-medium capitalize"
                        >
                          {localizeResourceStatusLabel(index.status, t)}
                        </Badge>
                      </td>
                      <td aria-hidden className={SPREADSHEET_FILLER_CELL_CLASS} />
                      <td
                        className={cn(
                          stickyActionsCellBaseClass,
                          isSystem ? 'bg-muted/30' : 'bg-background group-hover:bg-muted/50',
                        )}
                        style={spreadsheetActionsColStyle}
                      >
                        <div
                          className="flex h-full items-center justify-center py-1.5"
                          style={spreadsheetActionsColStyle}
                        >
                          {!isSystem && (
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <RowActionsMenuTrigger />
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                  onClick={() => handleDeleteIndex(index.key)}
                                >
                                  <MenuItemContent icon={Trash2}>
                                    {t('Delete')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            </div>
          </div>

          <div className="h-[54px] shrink-0 border-t border-border bg-background">
            <div className="@container flex h-full items-center justify-between gap-4 px-4">
              <div className="flex-1 min-w-0">
                <Pagination
                  currentPage={indexesPage}
                  totalItems={indexesTotal ?? 0}
                  pageSize={indexesLimit}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={(page) => navigateIndexesList({ page })}
                  onPageSizeChange={(newLimit) =>
                    navigateIndexesList({ page: 1, limit: newLimit })
                  }
                  itemLabel="indexes"
                />
              </div>
            </div>
          </div>
        </>
      )}

      <IndexDrawer
        open={indexDialogOpen}
        onOpenChange={setIndexDialogOpen}
        onSubmit={async (data) => {
          await createIndexMutation.mutateAsync(data)
        }}
        availableAttributes={availableAttributes}
        existingIndexes={indexesToShow.map((i) => ({ key: i.key }))}
        isLoading={createIndexMutation.isPending}
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete index')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete the index')}{' '}
              &quot;{indexToDelete}&quot;? {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteIndexMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteIndexMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
