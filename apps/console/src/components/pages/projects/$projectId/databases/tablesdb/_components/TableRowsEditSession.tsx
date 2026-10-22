import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  applyCommittedEditsToRowsCache,
  commitProjectTableRowEdits,
} from '@/lib/react-query/hooks/databases'
import {
  makePendingEditKey,
  rowCellValuesEqual,
  type PendingRowCellEdit,
  type RowCellValue,
} from '@/lib/database-row-inline-edits'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

type TableRowsEditSessionContextValue = {
  canWrite: boolean
  pendingCount: number
  pendingRowCount: number
  getCellDisplayValue: (
    tableId: string,
    rowId: string,
    columnKey: string,
    originalValue: RowCellValue,
  ) => RowCellValue
  isCellEdited: (tableId: string, rowId: string, columnKey: string) => boolean
  isRowEdited: (tableId: string, rowId: string) => boolean
  setCellEdit: (edit: Omit<PendingRowCellEdit, 'databaseId'>) => void
  clearCellEdit: (tableId: string, rowId: string, columnKey: string) => void
  discardAll: () => void
  commit: () => void
  isCommitting: boolean
  beginInlineEdit: (params: {
    tableId: string
    rowId: string
    columnKey: string
    commit: () => boolean | void
  }) => boolean
  endInlineEdit: (tableId: string, rowId: string, columnKey: string) => void
  commitActiveInlineEdit: () => boolean
  markSuppressNextDrawerOpen: () => void
  consumeSuppressNextDrawerOpen: () => boolean
}

const TableRowsEditSessionContext =
  createContext<TableRowsEditSessionContextValue | null>(null)

export function useTableRowsEditSession() {
  return useContext(TableRowsEditSessionContext)
}

type TableRowsEditSessionProviderProps = {
  projectId: string
  databaseId: string
  canWrite?: boolean
  children: ReactNode
}

export function TableRowsEditSessionProvider({
  projectId,
  databaseId,
  canWrite = true,
  children,
}: TableRowsEditSessionProviderProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [pendingEdits, setPendingEdits] = useState<
    Map<string, PendingRowCellEdit>
  >(() => new Map())
  const activeInlineEditRef = useRef<{
    key: string
    commit: () => boolean | void
  } | null>(null)
  const suppressNextDrawerOpenRef = useRef(false)

  const markSuppressNextDrawerOpen = useCallback(() => {
    suppressNextDrawerOpenRef.current = true
  }, [])

  const consumeSuppressNextDrawerOpen = useCallback(() => {
    if (!suppressNextDrawerOpenRef.current) return false
    suppressNextDrawerOpenRef.current = false
    return true
  }, [])

  const beginInlineEdit = useCallback(
    (params: {
      tableId: string
      rowId: string
      columnKey: string
      commit: () => boolean | void
    }): boolean => {
      const key = makePendingEditKey(
        params.tableId,
        params.rowId,
        params.columnKey,
      )
      if (
        activeInlineEditRef.current &&
        activeInlineEditRef.current.key !== key
      ) {
        const committed = activeInlineEditRef.current.commit()
        if (committed === false) return false
        activeInlineEditRef.current = null
      }
      activeInlineEditRef.current = { key, commit: params.commit }
      return true
    },
    [],
  )

  const endInlineEdit = useCallback(
    (tableId: string, rowId: string, columnKey: string) => {
      const key = makePendingEditKey(tableId, rowId, columnKey)
      if (activeInlineEditRef.current?.key === key) {
        activeInlineEditRef.current = null
      }
    },
    [],
  )

  const commitActiveInlineEdit = useCallback(() => {
    const active = activeInlineEditRef.current
    if (!active) return false
    const committed = active.commit()
    if (committed === false) return false
    activeInlineEditRef.current = null
    suppressNextDrawerOpenRef.current = true
    return true
  }, [])

  const setCellEdit = useCallback(
    (edit: Omit<PendingRowCellEdit, 'databaseId'>) => {
      if (!canWrite) return
      const key = makePendingEditKey(edit.tableId, edit.rowId, edit.columnKey)
      if (rowCellValuesEqual(edit.value, edit.originalValue)) {
        setPendingEdits((prev) => {
          if (!prev.has(key)) return prev
          const next = new Map(prev)
          next.delete(key)
          return next
        })
        return
      }
      setPendingEdits((prev) => {
        const next = new Map(prev)
        next.set(key, { ...edit, databaseId })
        return next
      })
    },
    [canWrite, databaseId],
  )

  const clearCellEdit = useCallback(
    (tableId: string, rowId: string, columnKey: string) => {
      const key = makePendingEditKey(tableId, rowId, columnKey)
      setPendingEdits((prev) => {
        if (!prev.has(key)) return prev
        const next = new Map(prev)
        next.delete(key)
        return next
      })
    },
    [],
  )

  const discardAll = useCallback(() => {
    activeInlineEditRef.current = null
    setPendingEdits(new Map())
  }, [])

  const getCellDisplayValue = useCallback(
    (
      tableId: string,
      rowId: string,
      columnKey: string,
      originalValue: RowCellValue,
    ) => {
      const key = makePendingEditKey(tableId, rowId, columnKey)
      return pendingEdits.get(key)?.value ?? originalValue
    },
    [pendingEdits],
  )

  const isCellEdited = useCallback(
    (tableId: string, rowId: string, columnKey: string) => {
      return pendingEdits.has(
        makePendingEditKey(tableId, rowId, columnKey),
      )
    },
    [pendingEdits],
  )

  const isRowEdited = useCallback(
    (tableId: string, rowId: string) => {
      const prefix = `${tableId}:${rowId}:`
      for (const key of pendingEdits.keys()) {
        if (key.startsWith(prefix)) return true
      }
      return false
    },
    [pendingEdits],
  )

  const commitMutation = useMutation({
    mutationFn: async () => {
      const edits = Array.from(pendingEdits.values())
      const result = await commitProjectTableRowEdits(
        projectId,
        'tablesdb',
        edits,
      )
      return { ...result, committedEdits: edits }
    },
    onSuccess: async (result) => {
      applyCommittedEditsToRowsCache(
        queryClient,
        projectId,
        databaseId,
        result.committedEdits,
      )
      activeInlineEditRef.current = null
      setPendingEdits(new Map())
      await Promise.all(
        result.affectedTables.map((tableId) =>
          queryClient.refetchQueries({
            queryKey: ['rows', 'project', projectId, databaseId, tableId],
          }),
        ),
      )
      toast.success(
        `Committed ${result.editCount} change${result.editCount === 1 ? '' : 's'} across ${result.rowCount} row${result.rowCount === 1 ? '' : 's'}`,
      )
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to commit changes')
    },
  })

  const pendingRowKeys = useMemo(() => {
    const keys = new Set<string>()
    for (const edit of pendingEdits.values()) {
      keys.add(`${edit.tableId}:${edit.rowId}`)
    }
    return keys
  }, [pendingEdits])

  const commit = useCallback(() => {
    commitMutation.mutate()
  }, [commitMutation])

  const value = useMemo<TableRowsEditSessionContextValue>(
    () => ({
      canWrite,
      pendingCount: pendingEdits.size,
      pendingRowCount: pendingRowKeys.size,
      getCellDisplayValue,
      isCellEdited,
      isRowEdited,
      setCellEdit,
      clearCellEdit,
      discardAll,
      commit,
      isCommitting: commitMutation.isPending,
      beginInlineEdit,
      endInlineEdit,
      commitActiveInlineEdit,
      markSuppressNextDrawerOpen,
      consumeSuppressNextDrawerOpen,
    }),
    [
      canWrite,
      pendingEdits.size,
      pendingRowKeys.size,
      getCellDisplayValue,
      isCellEdited,
      isRowEdited,
      setCellEdit,
      clearCellEdit,
      discardAll,
      commit,
      commitMutation.isPending,
      beginInlineEdit,
      endInlineEdit,
      commitActiveInlineEdit,
      markSuppressNextDrawerOpen,
      consumeSuppressNextDrawerOpen,
    ],
  )

  return (
    <TableRowsEditSessionContext.Provider value={value}>
      {children}
      {pendingEdits.size > 0 ? (
        <div className="fixed bottom-4 start-1/2 z-50 w-[min(100%,calc(100vw-2rem))] max-w-lg -translate-x-1/2 px-2 sm:px-0">
          <div className="mx-auto flex min-w-0 items-center justify-between gap-2 rounded-lg border border-amber-500/30 bg-background px-4 py-3 shadow-lg sm:gap-3 sm:px-6">
            <Badge variant="warning" className="h-6 shrink-0 px-2.5">
              {pendingEdits.size} unsaved change
              {pendingEdits.size === 1 ? '' : 's'}
            </Badge>
            <p className="hidden min-w-0 flex-1 truncate text-[12px] text-muted-foreground sm:block">
              {pendingRowKeys.size} row{pendingRowKeys.size === 1 ? '' : 's'}{' '}
              edited
            </p>
            <div className="flex shrink-0 items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={discardAll}
                disabled={commitMutation.isPending}
                className="h-8 text-xs"
              >
                {t('Discard')}
              </Button>
              <Button
                size="sm"
                onClick={commit}
                disabled={commitMutation.isPending || !canWrite}
                className="h-8"
              >
                {t('Commit changes')}
              </Button>
            </div>
          </div>
        </div>
      ) : null}
    </TableRowsEditSessionContext.Provider>
  )
}
