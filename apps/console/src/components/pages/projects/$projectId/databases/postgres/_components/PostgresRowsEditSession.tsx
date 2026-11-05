import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  makePostgresPendingEditKey,
  rowCellValuesEqual,
  type PendingPostgresRowCellEdit,
} from '@/lib/postgres-row-edits'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import { useCommitPostgresRowEdits } from '@/lib/react-query/hooks'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

type PostgresRowsEditSessionContextValue = {
  canWrite: boolean
  pendingCount: number
  pendingRowCount: number
  getCellDisplayValue: (
    tableId: string,
    rowKey: string,
    columnKey: string,
    originalValue: RowCellValue,
  ) => RowCellValue
  isCellEdited: (tableId: string, rowKey: string, columnKey: string) => boolean
  isRowEdited: (tableId: string, rowKey: string) => boolean
  setCellEdit: (edit: Omit<PendingPostgresRowCellEdit, 'databaseId'>) => void
  discardAll: () => void
  discardRows: (rowKeys: string[]) => void
  commit: () => void
  isCommitting: boolean
  beginInlineEdit: (params: {
    tableId: string
    rowKey: string
    columnKey: string
    commit: () => boolean | void
  }) => boolean
  endInlineEdit: (tableId: string, rowKey: string, columnKey: string) => void
  commitActiveInlineEdit: () => boolean
  markSuppressNextDrawerOpen: () => void
  consumeSuppressNextDrawerOpen: () => boolean
}

const PostgresRowsEditSessionContext =
  createContext<PostgresRowsEditSessionContextValue | null>(null)

export function usePostgresRowsEditSession() {
  return useContext(PostgresRowsEditSessionContext)
}

type PostgresRowsEditSessionProviderProps = {
  projectId: string
  databaseId: string
  tableId: string
  canWrite?: boolean
  children: ReactNode
}

export function PostgresRowsEditSessionProvider({
  projectId,
  databaseId,
  tableId,
  canWrite = true,
  children,
}: PostgresRowsEditSessionProviderProps) {
  const t = useT()
  const [pendingEdits, setPendingEdits] = useState<
    Map<string, PendingPostgresRowCellEdit>
  >(() => new Map())
  const activeInlineEditRef = useRef<{
    key: string
    commit: () => boolean | void
  } | null>(null)
  const suppressNextDrawerOpenRef = useRef(false)
  const commitMutation = useCommitPostgresRowEdits(
    projectId,
    databaseId,
    tableId,
  )

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
      rowKey: string
      columnKey: string
      commit: () => boolean | void
    }): boolean => {
      const key = makePostgresPendingEditKey(
        params.tableId,
        params.rowKey,
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
    (editTableId: string, rowKey: string, columnKey: string) => {
      const key = makePostgresPendingEditKey(editTableId, rowKey, columnKey)
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
    (edit: Omit<PendingPostgresRowCellEdit, 'databaseId'>) => {
      if (!canWrite) return
      const key = makePostgresPendingEditKey(
        edit.tableId,
        edit.rowKey,
        edit.columnKey,
      )
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

  const getCellDisplayValue = useCallback(
    (
      editTableId: string,
      rowKey: string,
      columnKey: string,
      originalValue: RowCellValue,
    ) => {
      const key = makePostgresPendingEditKey(editTableId, rowKey, columnKey)
      return pendingEdits.get(key)?.value ?? originalValue
    },
    [pendingEdits],
  )

  const isCellEdited = useCallback(
    (editTableId: string, rowKey: string, columnKey: string) => {
      const key = makePostgresPendingEditKey(editTableId, rowKey, columnKey)
      return pendingEdits.has(key)
    },
    [pendingEdits],
  )

  const isRowEdited = useCallback(
    (editTableId: string, rowKey: string) => {
      const prefix = `${editTableId}:${rowKey}:`
      for (const key of pendingEdits.keys()) {
        if (key.startsWith(prefix)) return true
      }
      return false
    },
    [pendingEdits],
  )

  const discardAll = useCallback(() => {
    setPendingEdits(new Map())
    activeInlineEditRef.current = null
  }, [])

  const discardRows = useCallback((rowKeys: string[]) => {
    if (rowKeys.length === 0) return
    const rowKeySet = new Set(rowKeys)
    setPendingEdits((prev) => {
      let changed = false
      const next = new Map(prev)
      for (const [key, edit] of prev) {
        if (rowKeySet.has(edit.rowKey)) {
          next.delete(key)
          changed = true
        }
      }
      return changed ? next : prev
    })
    const active = activeInlineEditRef.current
    if (
      active &&
      rowKeys.some((rowKey) => active.key.includes(`:${rowKey}:`))
    ) {
      activeInlineEditRef.current = null
    }
  }, [])

  const commit = useCallback(() => {
    if (!canWrite || pendingEdits.size === 0) return
    const edits = Array.from(pendingEdits.values())
    commitMutation.mutate(edits, {
      onSuccess: () => {
        setPendingEdits(new Map())
        toast.success(t('Changes saved'))
      },
      onError: (error) => {
        toast.error(getErrorMessage(error) ?? t('Failed to save changes'))
      },
    })
  }, [canWrite, commitMutation, pendingEdits, t])

  const pendingRowCount = useMemo(() => {
    const rowKeys = new Set<string>()
    for (const edit of pendingEdits.values()) {
      rowKeys.add(edit.rowKey)
    }
    return rowKeys.size
  }, [pendingEdits])

  const value = useMemo(
    () => ({
      canWrite,
      pendingCount: pendingEdits.size,
      pendingRowCount,
      getCellDisplayValue,
      isCellEdited,
      isRowEdited,
      setCellEdit,
      discardAll,
      discardRows,
      commit,
      isCommitting: commitMutation.isPending,
      beginInlineEdit,
      endInlineEdit,
      commitActiveInlineEdit,
      markSuppressNextDrawerOpen,
      consumeSuppressNextDrawerOpen,
    }),
    [
      beginInlineEdit,
      canWrite,
      commit,
      commitActiveInlineEdit,
      commitMutation.isPending,
      consumeSuppressNextDrawerOpen,
      discardAll,
      discardRows,
      endInlineEdit,
      getCellDisplayValue,
      isCellEdited,
      isRowEdited,
      markSuppressNextDrawerOpen,
      pendingEdits.size,
      pendingRowCount,
      setCellEdit,
    ],
  )

  return (
    <PostgresRowsEditSessionContext.Provider value={value}>
      {children}
      {pendingEdits.size > 0 ? (
        <div className="fixed bottom-4 start-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-lg border border-border bg-background px-4 py-3 shadow-lg">
          <Badge variant="warning" className="text-[11px] shrink-0">
            {pendingEdits.size} unsaved change{pendingEdits.size === 1 ? '' : 's'}
          </Badge>
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[13px]"
            onClick={discardAll}
            disabled={commitMutation.isPending}
          >
            {t('Discard')}
          </Button>
          <Button
            size="sm"
            className="h-8 text-[13px]"
            onClick={commit}
            disabled={commitMutation.isPending}
          >
            {t('Commit changes')}
          </Button>
        </div>
      ) : null}
    </PostgresRowsEditSessionContext.Provider>
  )
}
