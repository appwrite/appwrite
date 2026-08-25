import {
  useCallback,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { flushSync } from 'react-dom'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  fetchConsoleAccount,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  getDatabaseTableRowColumnWidthsFromPrefs,
  mergeDatabaseTableRowColumnWidthsTableIntoPrefs,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  applyColumnResizeRailPosition,
  horizontalResizeDeltaPx,
  setBodyResizeDragActive,
} from '@/lib/layout/horizontal-resize'
import {
  MYSQL_ROWS_DATA_COLUMN_DEFAULT_WIDTH_PX,
  MYSQL_ROWS_DATA_COLUMN_MAX_WIDTH_PX,
  MYSQL_ROWS_DATA_COLUMN_MIN_WIDTH_PX,
} from './mysql-spreadsheet-chrome'
import type { RefObject } from 'react'

function clampColumnWidth(width: number): number {
  return Math.min(
    MYSQL_ROWS_DATA_COLUMN_MAX_WIDTH_PX,
    Math.max(MYSQL_ROWS_DATA_COLUMN_MIN_WIDTH_PX, width),
  )
}

export function useMysqlRowsColumnResize(
  databaseId: string,
  tableId: string,
  columnKeys: string[],
  scrollRef: RefObject<HTMLDivElement | null>,
) {
  const queryClient = useQueryClient()
  const { account } = useAuth()

  const columnKeysRef = useRef(columnKeys)
  columnKeysRef.current = columnKeys

  const widthsFromPrefs = useMemo(() => {
    const prefs = (account as { prefs?: UserPrefs } | undefined)?.prefs
    const raw = getDatabaseTableRowColumnWidthsFromPrefs(
      prefs,
      databaseId,
      tableId,
    )
    const next: Record<string, number> = {}
    for (const [key, value] of Object.entries(raw)) {
      if (!key) continue
      const n = typeof value === 'number' ? value : Number(value)
      if (!Number.isFinite(n)) continue
      next[key] = clampColumnWidth(n)
    }
    return next
  }, [account, databaseId, tableId])

  const [columnWidths, setColumnWidths] =
    useState<Record<string, number>>(widthsFromPrefs)
  const [resizingColumnKey, setResizingColumnKey] = useState<string | null>(
    null,
  )

  const columnWidthsRef = useRef<Record<string, number>>({})
  if (resizingColumnKey == null) {
    columnWidthsRef.current = columnWidths
  }

  const tableLayerRef = useRef<HTMLDivElement | null>(null)
  const headerThRefs = useRef<Map<string, HTMLTableCellElement>>(new Map())
  const colRefs = useRef<Map<string, HTMLTableColElement>>(new Map())
  const railRefs = useRef<Map<string, HTMLButtonElement>>(new Map())

  useLayoutEffect(() => {
    setColumnWidths(widthsFromPrefs)
  }, [databaseId, tableId, widthsFromPrefs])

  const getColumnWidthPx = useCallback(
    (columnKey: string) => {
      const width = columnWidths[columnKey]
      if (typeof width === 'number' && Number.isFinite(width)) {
        return clampColumnWidth(width)
      }
      return MYSQL_ROWS_DATA_COLUMN_DEFAULT_WIDTH_PX
    },
    [columnWidths],
  )

  const repositionRails = useCallback(() => {
    const layer = tableLayerRef.current
    if (!layer) return
    for (const columnKey of columnKeysRef.current) {
      const th = headerThRefs.current.get(columnKey)
      const rail = railRefs.current.get(columnKey)
      if (!th || !rail) continue
      const isLastColumn =
        columnKeysRef.current[columnKeysRef.current.length - 1] === columnKey
      applyColumnResizeRailPosition(rail, layer, th, {
        maxInsetInlineStartPx: isLastColumn ? layer.getBoundingClientRect().width : undefined,
      })
    }
  }, [])

  const applyDraggedWidthPx = useCallback(
    (columnKey: string, widthPx: number) => {
      const next = clampColumnWidth(Math.round(widthPx))
      const isLastColumn =
        columnKeysRef.current[columnKeysRef.current.length - 1] === columnKey
      columnWidthsRef.current = {
        ...columnWidthsRef.current,
        [columnKey]: next,
      }
      const colEl = colRefs.current.get(columnKey)
      if (colEl) {
        colEl.style.width = `${next}px`
        colEl.style.minWidth = `${next}px`
        if (isLastColumn) {
          colEl.style.maxWidth = ''
        } else {
          colEl.style.maxWidth = `${next}px`
        }
      }
      const thEl = headerThRefs.current.get(columnKey)
      if (thEl) {
        thEl.style.width = `${next}px`
        thEl.style.minWidth = `${next}px`
        if (isLastColumn) {
          thEl.style.maxWidth = ''
        } else {
          thEl.style.maxWidth = `${next}px`
        }
      }
      repositionRails()
    },
    [repositionRails],
  )

  const persistColumnWidths = useCallback(
    async (widths: Record<string, number>, allowedKeys: readonly string[]) => {
      const allowed = new Set(allowedKeys.filter(Boolean))
      const pruned: Record<string, number> = {}
      for (const key of allowed) {
        const width = widths[key]
        if (typeof width === 'number' && Number.isFinite(width)) {
          pruned[key] = clampColumnWidth(width)
        }
      }
      try {
        const acct = await fetchConsoleAccount()
        const prefs = mergeDatabaseTableRowColumnWidthsTableIntoPrefs(
          (acct.prefs || {}) as UserPrefs,
          databaseId,
          tableId,
          pruned,
        )
        const updatedAccount = await updateAccountPrefs(prefs)
        syncConsoleAccountAfterMutation(queryClient, {
          apiResult: updatedAccount,
        })
      } catch {
        /* preference save is best-effort */
      }
    },
    [databaseId, queryClient, tableId],
  )

  const handleResizePointerDown = useCallback(
    (columnKey: string) => (event: React.PointerEvent<HTMLButtonElement>) => {
      if (!columnKey) return
      event.preventDefault()
      event.stopPropagation()
      setBodyResizeDragActive(true)
      const button = event.currentTarget
      button.setPointerCapture(event.pointerId)
      const startX = event.clientX
      const initialWidth = getColumnWidthPx(columnKey)
      flushSync(() => {
        setResizingColumnKey(columnKey)
      })
      applyDraggedWidthPx(columnKey, initialWidth)
      const onMove = (ev: PointerEvent) => {
        applyDraggedWidthPx(
          columnKey,
          initialWidth + horizontalResizeDeltaPx(startX, ev.clientX),
        )
      }
      const onUp = () => {
        setBodyResizeDragActive(false)
        try {
          button.releasePointerCapture(event.pointerId)
        } catch {
          /* already released */
        }
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        window.removeEventListener('pointercancel', onUp)
        flushSync(() => {
          setResizingColumnKey(null)
          setColumnWidths({ ...columnWidthsRef.current })
        })
        void persistColumnWidths(
          columnWidthsRef.current,
          columnKeysRef.current,
        )
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
      window.addEventListener('pointercancel', onUp)
    },
    [applyDraggedWidthPx, getColumnWidthPx, persistColumnWidths],
  )

  const columnResizeLayoutKey = columnKeys.join('\u0001')

  useLayoutEffect(() => {
    repositionRails()
  }, [columnResizeLayoutKey, columnWidths, repositionRails])

  useLayoutEffect(() => {
    const scroll = scrollRef.current
    const layer = tableLayerRef.current
    if (!scroll || !layer || columnKeysRef.current.length === 0) return

    const measure = () => {
      repositionRails()
    }

    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(scroll)
    ro.observe(layer)
    scroll.addEventListener('scroll', measure, { passive: true })
    window.addEventListener('resize', measure)
    return () => {
      ro.disconnect()
      scroll.removeEventListener('scroll', measure)
      window.removeEventListener('resize', measure)
    }
  }, [columnResizeLayoutKey, columnWidths, repositionRails, scrollRef])

  return {
    tableLayerRef,
    headerThRefs,
    colRefs,
    railRefs,
    getColumnWidthPx,
    resizingColumnKey,
    handleResizePointerDown,
    repositionRails,
  }
}
