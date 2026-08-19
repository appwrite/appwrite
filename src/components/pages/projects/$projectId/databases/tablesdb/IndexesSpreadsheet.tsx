import { cn } from '@/lib/utils'
import {
  SPREADSHEET_FILLER_CELL_CLASS,
  SPREADSHEET_FILLER_HEADER_CLASS,
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { Key, Trash2, Check, X, Pencil, Lightbulb } from 'lucide-react'
import { type Collection } from '@/lib/utils/mock-data'
import { useState, useEffect, useRef, useMemo } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useProjectTableColumns,
  createProjectTableIndex,
  deleteProjectTableIndex,
  useProjectTableIndexes,
} from '@/lib/react-query/hooks'
import { COLUMNS_INDEXES_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { IndexDrawer, type IndexFormData } from './Index'
import { sdk } from '@/lib/appwrite/sdk'
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
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useT } from '@/lib/i18n/translate'
import {
  localizeResourceStatusLabel,
  localizeTableIndexTypeLabel,
} from '@/lib/i18n/resource-status-labels'

const DB_KIND = 'tablesdb' as const satisfies DatabaseRouteKind

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

export type IndexesSpreadsheetProps = {
  table: Collection
  onCreateReady?: (openDialog: () => void) => void
  onSuggestReady?: (openDialog: () => void) => void
  onIndexesAbilityChange?: (canCreate: boolean) => void
  canWriteTables?: boolean
  filterMap?: Map<CompactFilterKey, string>
}

// Spreadsheet-like view for Indexes
export function IndexesSpreadsheet({
  table,
  onCreateReady,
  onSuggestReady,
  onIndexesAbilityChange,
  canWriteTables: _canWriteTables = true,
  filterMap: filterMapProp,
}: IndexesSpreadsheetProps) {
  const t = useT()
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, DB_KIND)
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id
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
    // Prefer router search state over URL so page size change takes effect even if URL lags
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
  const [selectedIndex, setSelectedIndex] = useState<unknown>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [indexToDelete, setIndexToDelete] = useState<string | null>(null)
  const [contextDialogOpen, setContextDialogOpen] = useState(false)
  const [suggestedIndexes, setSuggestedIndexes] = useState<unknown[]>([])
  const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false)

  const queryClient = useQueryClient()

  // Fetch indexes from the project SDK (listIndexes with optional filter queries, ordered by $createdAt asc, paginated)
  const {
    indexes: apiIndexes,
    total: indexesTotal,
    isLoading: indexesLoading,
    isFetching: indexesFetching,
  } = useProjectTableIndexes(
    projectId,
    databaseId,
    DB_KIND,
    tableId,
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

  // Fetch columns for index creation (first page, default limit)
  const { columns: availableColumns, isLoading: columnsLoading } =
    useProjectTableColumns(projectId, databaseId, DB_KIND, tableId)

  // TablesDB only: notify parent when the table has no non-relationship columns.
  useEffect(() => {
    if (!onIndexesAbilityChange || columnsLoading) return
    const nonRelationshipColumns =
      availableColumns?.filter(
        (c: { type?: string }) => c.type !== 'relationship',
      ) ?? []
    onIndexesAbilityChange(nonRelationshipColumns.length > 0)
  }, [availableColumns, columnsLoading, onIndexesAbilityChange])

  // Create index mutation
  const createIndexMutation = useMutation({
    mutationFn: async (data: IndexFormData) => {
      // Transform form data to API format - arrays must correspond to columns
      const apiData: unknown = {
        key: data.key,
        type: data.type,
        columns: data.columns.map((c: IndexColumnEntry) => c.column),
      }

      // Orders array must match columns array length
      const allOrders = data.columns.map((c: IndexColumnEntry) => c.order)
      const hasAnyOrders = allOrders.some((o) => o !== null)
      if (hasAnyOrders) {
        apiData.orders = allOrders
      }

      // Lengths array must match columns array length (only for key indexes)
      if (data.type === 'key') {
        const allLengths = data.columns.map((c: IndexColumnEntry) => c.length)
        const hasAnyLengths = allLengths.some((l) => l !== null && l > 0)
        if (hasAnyLengths) {
          apiData.lengths = allLengths
        }
      }

      return await createProjectTableIndex(
        projectId,
        databaseId,
        DB_KIND,
        tableId,
        apiData,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setIndexDialogOpen(false)
      setSelectedIndex(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create index'))
    },
  })

  // Delete index mutation
  const deleteIndexMutation = useMutation({
    mutationFn: async (indexKey: string) => {
      return await deleteProjectTableIndex(
        projectId,
        databaseId,
        DB_KIND,
        tableId,
        indexKey,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
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
    setSelectedIndex(null)
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

  // AI Suggestion handlers
  const handleGenerateSuggestions = async () => {
    setIsLoadingSuggestions(true)
    try {
      const projectSdk = sdk.forProject(projectId)
      const result = await projectSdk.console.suggestIndexes({
        databaseId,
        tableId,
        min: 3,
        max: 5,
      })

      // Map API suggestions to display format with suggestion flag
      const mapped = result.indexes.map((idx: unknown) => ({
        key: idx.key || 'unnamed',
        type: idx.type || 'key',
        columns: idx.columns || [],
        orders: idx.orders || [],
        lengths: idx.lengths || [],
        isSuggestion: true,
        originalData: idx,
      }))

      setSuggestedIndexes(mapped)
      setContextDialogOpen(false)
      toast.success(
        `${t('Generated')} ${mapped.length} ${t('index suggestions')}`,
      )

      // Scroll to first suggestion after DOM updates
      setTimeout(() => {
        const firstSuggestionRow = document.querySelector(
          '[data-suggestion-row="true"]',
        )
        if (firstSuggestionRow) {
          firstSuggestionRow.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
          })
        }
      }, 100)
    } catch (error) {
      toast.error(getErrorMessage(error))
      setContextDialogOpen(false)
    } finally {
      setIsLoadingSuggestions(false)
    }
  }

  // Backend limit: 767 bytes = 191 chars (utf8mb4). Key indexes need explicit length for string/varchar to avoid "index full column" exceeding 767.
  const INDEX_MAX_LENGTH = 767
  const INDEX_MAX_CHARS = 191

  const handleApproveSuggestion = async (suggestionKey: string) => {
    try {
      // Get the current suggestion data from state (in case it was edited)
      const suggestion = suggestedIndexes.find((s) => s.key === suggestionKey)
      if (!suggestion) {
        toast.error(t('Suggestion not found'))
        return
      }

      // Build column map: string/varchar key -> attribute size (for key index length capping and safe default)
      const columnSizeMap = new Map<string, number>()
      availableColumns?.forEach((c: { key: string; type?: string; size?: number }) => {
        if ((c.type === 'string' || c.type === 'varchar') && typeof c.size === 'number') {
          columnSizeMap.set(c.key, c.size)
        }
      })

      // Map columns and filter out invalid ones; for key indexes on string/varchar set safe default length when null (avoid lengths: [null,null] → backend indexes full column → 767 error)
      const validColumns = suggestion.columns
        .map((col: string, idx: number) => {
          const columnDef = availableColumns.find((c) => c.key === col)

          if (columnDef?.array) {
            return null
          }

          const columnType = columnDef?.type
          const supportsLength =
            suggestion.type === 'key' &&
            (columnType === 'string' || columnType === 'varchar')

          let length: number | null = suggestion.lengths?.[idx] ?? null
          if (supportsLength) {
            if (length != null && length > 0) {
              const maxSize = columnSizeMap.get(col)
              const cap = maxSize != null ? Math.min(maxSize, INDEX_MAX_LENGTH) : INDEX_MAX_LENGTH
              if (length > cap) length = cap
            } else if (length != null && length > INDEX_MAX_LENGTH) {
              length = INDEX_MAX_LENGTH
            } else {
              // Key index on string/varchar with no length: set safe default so backend doesn't index full column (would exceed 767)
              const colSize = columnSizeMap.get(col) ?? 255
              length = Math.min(colSize, INDEX_MAX_CHARS)
            }
          }

          return {
            column: col,
            order: suggestion.orders?.[idx] || null,
            length: supportsLength ? length : null,
          }
        })
        .filter(Boolean) // Remove null entries (array columns)

      // Validate that we still have columns after filtering
      if (validColumns.length === 0) {
        toast.error(
          'Cannot create index: Array columns are not supported for indexes',
        )
        return
      }

      const indexData: IndexFormData = {
        key: suggestion.key,
        type: suggestion.type,
        columns: validColumns,
      }

      await createIndexMutation.mutateAsync(indexData)

      // Remove from suggestions
      setSuggestedIndexes((prev) => prev.filter((s) => s.key !== suggestionKey))

      toast.success(
        `${t('Index')} "${suggestion.key}" ${t('created successfully')}`,
      )
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleRemoveSuggestion = (suggestionKey: string) => {
    setSuggestedIndexes((prev) => prev.filter((s) => s.key !== suggestionKey))
  }

  const handleEditSuggestion = (suggestion: unknown) => {
    // Pass suggestion as-is with isSuggestion flag
    // IndexDrawer will handle the conversion from columns array to IndexColumnEntry objects
    setSelectedIndex({
      ...suggestion,
      isSuggestion: true,
    })
    setIndexDialogOpen(true)
  }

  const handleSuggestionSubmit = (key: string, data: IndexFormData) => {
    // Update the suggestion in the list, ensuring all fields are properly merged
    setSuggestedIndexes((prev) =>
      prev.map((s) =>
        s.key === key
          ? {
            ...s,
            key: data.key,
            type: data.type,
            columns: data.columns.map((c) => c.column),
            orders: data.columns.map((c) => c.order), // Keep nulls to maintain array indices
            lengths: data.columns.map((c) => c.length), // Keep nulls to maintain array indices
            isSuggestion: true,
          }
          : s,
      ),
    )
    setIndexDialogOpen(false)
    setSelectedIndex(null)
    toast.success(t('Suggestion updated'))
  }

  const handleIndexSubmitWrapper = async (data: IndexFormData) => {
    if (selectedIndex?.isSuggestion) {
      // Update suggestion in place
      handleSuggestionSubmit(selectedIndex.key, data)
    } else {
      await createIndexMutation.mutateAsync(data)
    }
  }

  const handleOpenSuggestDialog = () => {
    setContextDialogOpen(true)
  }

  // Expose create function to parent
  useEffect(() => {
    if (onCreateReady) {
      onCreateReady(handleCreateIndex)
    }
  }, [onCreateReady])

  // Expose suggest function to parent
  useEffect(() => {
    if (onSuggestReady) {
      onSuggestReady(handleOpenSuggestDialog)
    }
  }, [onSuggestReady])

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

  // Map API indexes to ensure all fields are present
  const mappedIndexes = apiIndexes.map((idx: unknown) => ({
    key: idx.key,
    type: idx.type,
    columns: idx.columns || [],
    orders: idx.orders || [],
    lengths: idx.lengths || [],
    status: idx.status || 'available',
    $id: idx.$id,
  }))

  const displayIndexes = mappedIndexes

  // Keep showing previous results while fetching new filter results (no empty state flash)
  const lastDisplayIndexesRef = useRef<typeof displayIndexes>([])
  useEffect(() => {
    if (!indexesFetching && displayIndexes.length > 0) {
      lastDisplayIndexesRef.current = displayIndexes
    }
  }, [indexesFetching, displayIndexes])
  const indexesToShow =
    indexesFilterMap.size > 0 &&
      indexesFetching &&
      lastDisplayIndexesRef.current.length > 0
      ? lastDisplayIndexesRef.current
      : displayIndexes

  // Combine regular indexes with suggestions
  const allIndexes = [...indexesToShow, ...suggestedIndexes]

  const showInitialLoading =
    indexesLoading && lastDisplayIndexesRef.current.length === 0

  const indexesFullyEmpty =
    !indexesFetching &&
    apiIndexes.length === 0 &&
    indexesFilterMap.size === 0 &&
    suggestedIndexes.length === 0

  const indexesFilteredEmpty =
    !indexesFetching &&
    apiIndexes.length === 0 &&
    indexesFilterMap.size > 0

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
          <div
            className={cn(
              'flex-1 overflow-auto overscroll-contain',
              suggestedIndexes.length > 0 && 'pb-24',
            )}
          >
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
                      {t('Columns')}
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
                {allIndexes.map((index: unknown) => {
                  const isSystem = index.key?.startsWith('_key_')
                  const isSuggestion = index.isSuggestion === true
                  return (
                    <tr
                      key={index.key || 'unnamed'}
                      data-suggestion-row={isSuggestion ? 'true' : undefined}
                      className={cn(
                        'group transition-colors hover:bg-muted/50',
                        isSystem && 'bg-muted/30',
                        isSuggestion && 'bg-amber-500/5',
                      )}
                    >
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        <div className="flex items-center gap-2 justify-between">
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
                          {isSuggestion && (
                            <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                  handleApproveSuggestion(index.key)
                                }
                                className="h-6 w-6 p-0 rounded bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 cursor-pointer"
                                title={t('Approve')}
                              >
                                <Check className="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                  handleRemoveSuggestion(index.key)
                                }
                                className="h-6 w-6 p-0 rounded bg-red-500/10 hover:bg-red-500/20 text-red-600 dark:text-red-400 cursor-pointer"
                                title={t('Reject')}
                              >
                                <X className="h-3.5 w-3.5" />
                              </Button>
                              <div className="h-4 w-px bg-border mx-0.5" />
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => handleEditSuggestion(index)}
                                className="h-6 w-6 p-0 rounded bg-blue-500/10 hover:bg-blue-500/20 text-blue-600 dark:text-blue-400 cursor-pointer"
                                title={t('Edit')}
                              >
                                <Pencil className="h-3.5 w-3.5" />
                              </Button>
                            </div>
                          )}
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
                            // Ensure length is a number and greater than 0
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
                          isSystem
                            ? 'bg-muted/30'
                            : isSuggestion
                              ? 'bg-amber-500/5'
                              : 'bg-background group-hover:bg-muted/50',
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
                                <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
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

          {/* Sticky Pagination Footer */}
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
              {suggestedIndexes.length > 0 && (
                <div className="flex-shrink-0 text-[12px] text-amber-600 dark:text-amber-400">
                  {suggestedIndexes.length} suggestion
                  {suggestedIndexes.length !== 1 ? 's' : ''}
                </div>
              )}
            </div>
          </div>
        </>
      )}

      {/* Index Form Dialog */}
      <IndexDrawer
        open={indexDialogOpen}
        onOpenChange={(open) => {
          setIndexDialogOpen(open)
          if (!open && selectedIndex?.isSuggestion) {
            setSelectedIndex(null)
          }
        }}
        onSubmit={handleIndexSubmitWrapper}
        index={selectedIndex}
        availableColumns={availableColumns}
        existingIndexes={indexesToShow.map((i: { key: string }) => ({
          key: i.key,
        }))}
        isLoading={createIndexMutation.isPending}
      />

      {/* Delete Confirmation Dialog */}
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

      {/* Context Input Dialog */}
      <Dialog
        open={contextDialogOpen}
        onOpenChange={(open) => {
          if (!isLoadingSuggestions) {
            setContextDialogOpen(open)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          {isLoadingSuggestions ? (
            <>
              <DialogHeader className="px-6 pt-6 pb-4 text-start">
                <DialogTitle>{t('Generating suggestions')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t('AI is analyzing your table structure and generating index suggestions...')}
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0 mt-8 mb-4 flex flex-col items-center justify-center gap-4">
                <div className="relative">
                  <Lightbulb className="h-12 w-12 text-amber-500 animate-pulse" />
                  <div className="absolute inset-0 bg-amber-500/20 rounded-full animate-ping" />
                </div>
                <p className="text-[13px] text-muted-foreground text-center">
                  {t('This may take a few seconds...')}
                </p>
              </div>
            </>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                const formData = new FormData(e.currentTarget)
                const context = formData.get('context') as string
                handleGenerateSuggestions(context)
              }}
            >
              <DialogHeader className="px-6 pt-6 pb-4 text-start">
                <DialogTitle>{t('AI index suggestions')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t('Provide optional context or instructions to help generate better index suggestions for this table.')}
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                <div className="space-y-2 mt-4">
                  <Label htmlFor="context" className="text-[12px] font-medium">
                    {t('Context (Optional)')}
                  </Label>
                  <Textarea
                    id="context"
                    name="context"
                    placeholder={t('E.g., This table will have millions of records and needs optimized search...')}
                    className="min-h-[100px] text-[13px]"
                  />
                  <p className="text-[11px] text-muted-foreground">
                    {t('The AI will analyze your table columns and structure to suggest relevant indexes.')}
                  </p>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setContextDialogOpen(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button type="submit">{t('Generate suggestions')}</Button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>

      {/* Bulk Action Bar for Suggestions */}
      {suggestedIndexes.length > 0 && (
        <div className="absolute bottom-4 start-1/2 z-50 -translate-x-1/2">
          <div className="flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              <Lightbulb className="h-3 w-3 me-1.5" />
              {suggestedIndexes.length} suggestion
              {suggestedIndexes.length !== 1 ? 's' : ''}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setSuggestedIndexes([])}
                className="h-8"
              >
                {t('Clear all')}
              </Button>
              <Button
                size="sm"
                onClick={async () => {
                  for (const suggestion of suggestedIndexes) {
                    await handleApproveSuggestion(suggestion.key)
                  }
                }}
                disabled={createIndexMutation.isPending}
                className="h-8"
              >
                <Check className="h-3.5 w-3.5 me-1.5" />
                {t('Approve all')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
