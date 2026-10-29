import {
  useCallback,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type PointerEvent as ReactPointerEvent,
  type RefObject,
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
  clampPartnerProjectsColumnWidthPx,
  getPartnerProjectsColumnWidthsFromPrefs,
  mergePartnerProjectsColumnWidthsIntoPrefs,
  mergePartnerProjectsColumnWidthsWithDefaults,
  PARTNER_PROJECTS_COLUMN_DEFAULT_WIDTHS,
  PARTNER_PROJECTS_USAGE_COLUMN_WIDTH_KEYS,
  type PartnerProjectsSpreadsheetColumnWidthKey,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  applyColumnResizeRailPosition,
  horizontalResizeDeltaPx,
  setBodyResizeDragActive,
} from '@/lib/layout/horizontal-resize'
import { areColumnWidthRecordsEqual } from '@/lib/resizable-layout'

const BASE_COLUMN_KEYS = [
  'status',
  'project',
  'projectId',
  'endpoint',
  'created',
] as const satisfies readonly PartnerProjectsSpreadsheetColumnWidthKey[]

export const PARTNER_PROJECTS_ACTIONS_COLUMN_WIDTH_PX = 40

export function getPartnerProjectsSpreadsheetVisibleColumnKeys(
  showUsage: boolean,
): PartnerProjectsSpreadsheetColumnWidthKey[] {
  return showUsage
    ? [...BASE_COLUMN_KEYS, ...PARTNER_PROJECTS_USAGE_COLUMN_WIDTH_KEYS]
    : [...BASE_COLUMN_KEYS]
}

export function usePartnerProjectsSpreadsheetColumnResize(
  showUsage: boolean,
  scrollRef: RefObject<HTMLDivElement | null>,
) {
  const queryClient = useQueryClient()
  const { account } = useAuth()
  const visibleKeys = useMemo(
    () => getPartnerProjectsSpreadsheetVisibleColumnKeys(showUsage),
    [showUsage],
  )
  const visibleKeysRef = useRef(visibleKeys)
  visibleKeysRef.current = visibleKeys

  const prefs = (account as { prefs?: UserPrefs } | undefined)?.prefs
  const widthsFromPrefs = useMemo(() => {
    const raw = getPartnerProjectsColumnWidthsFromPrefs(prefs)
    return mergePartnerProjectsColumnWidthsWithDefaults(raw)
  }, [prefs])

  const [columnWidths, setColumnWidths] = useState(widthsFromPrefs)
  const [resizingColumnKey, setResizingColumnKey] =
    useState<PartnerProjectsSpreadsheetColumnWidthKey | null>(null)

  const columnWidthsRef = useRef(columnWidths)
  if (resizingColumnKey == null) {
    columnWidthsRef.current = columnWidths
  }

  const tableLayerRef = useRef<HTMLDivElement | null>(null)
  const tableRef = useRef<HTMLTableElement | null>(null)
  const headerThRefs = useRef(
    new Map<PartnerProjectsSpreadsheetColumnWidthKey, HTMLTableCellElement>(),
  )
  const colRefs = useRef(
    new Map<PartnerProjectsSpreadsheetColumnWidthKey, HTMLTableColElement>(),
  )
  const railRefs = useRef(
    new Map<PartnerProjectsSpreadsheetColumnWidthKey, HTMLButtonElement>(),
  )

  useLayoutEffect(() => {
    setColumnWidths((prev) =>
      areColumnWidthRecordsEqual(prev, widthsFromPrefs) ? prev : widthsFromPrefs,
    )
  }, [widthsFromPrefs])

  const getColumnWidthPx = useCallback(
    (columnKey: PartnerProjectsSpreadsheetColumnWidthKey) => {
      const width = columnWidths[columnKey]
      if (typeof width === 'number' && Number.isFinite(width)) {
        return clampPartnerProjectsColumnWidthPx(columnKey, width)
      }
      return clampPartnerProjectsColumnWidthPx(
        columnKey,
        widthsFromPrefs[columnKey] ??
          PARTNER_PROJECTS_COLUMN_DEFAULT_WIDTHS[columnKey],
      )
    },
    [columnWidths, widthsFromPrefs],
  )

  const sumVisibleColumnWidthsPx = useCallback(
    (
      widths: Partial<
        Record<PartnerProjectsSpreadsheetColumnWidthKey, number>
      >,
    ) => {
      let total = 0
      for (const columnKey of visibleKeysRef.current) {
        const width = widths[columnKey]
        total += clampPartnerProjectsColumnWidthPx(
          columnKey,
          typeof width === 'number' && Number.isFinite(width)
            ? width
            : (widthsFromPrefs[columnKey] ??
                PARTNER_PROJECTS_COLUMN_DEFAULT_WIDTHS[columnKey]),
        )
      }
      return total
    },
    [widthsFromPrefs],
  )

  const tableWidthPx = useMemo(
    () =>
      sumVisibleColumnWidthsPx(columnWidths) +
      PARTNER_PROJECTS_ACTIONS_COLUMN_WIDTH_PX,
    [columnWidths, sumVisibleColumnWidthsPx, visibleKeys],
  )

  const applyTableWidthPx = useCallback((widthPx: number) => {
    const width = `${widthPx}px`
    const layer = tableLayerRef.current
    if (layer) {
      layer.style.minWidth = width
      layer.style.width = ''
    }
    const table = tableRef.current
    if (table) {
      table.style.minWidth = width
      table.style.width = '100%'
    }
  }, [])

  const repositionRails = useCallback(() => {
    const layer = tableLayerRef.current
    if (!layer) return
    const keys = visibleKeysRef.current
    for (const columnKey of keys) {
      const th = headerThRefs.current.get(columnKey)
      const rail = railRefs.current.get(columnKey)
      if (!th || !rail) continue
      const isLastColumn = keys[keys.length - 1] === columnKey
      applyColumnResizeRailPosition(rail, layer, th, {
        maxInsetInlineStartPx: isLastColumn
          ? layer.getBoundingClientRect().width
          : undefined,
      })
    }
  }, [])

  const applyDraggedWidthPx = useCallback(
    (
      columnKey: PartnerProjectsSpreadsheetColumnWidthKey,
      widthPx: number,
    ) => {
      const next = clampPartnerProjectsColumnWidthPx(columnKey, widthPx)
      columnWidthsRef.current = {
        ...columnWidthsRef.current,
        [columnKey]: next,
      }
      const colEl = colRefs.current.get(columnKey)
      if (colEl) {
        colEl.style.width = `${next}px`
        colEl.style.minWidth = `${next}px`
        colEl.style.maxWidth = `${next}px`
      }
      const thEl = headerThRefs.current.get(columnKey)
      if (thEl) {
        thEl.style.width = `${next}px`
        thEl.style.minWidth = `${next}px`
        thEl.style.maxWidth = `${next}px`
      }
      applyTableWidthPx(
        sumVisibleColumnWidthsPx(columnWidthsRef.current) +
          PARTNER_PROJECTS_ACTIONS_COLUMN_WIDTH_PX,
      )
      repositionRails()
    },
    [applyTableWidthPx, repositionRails, sumVisibleColumnWidthsPx],
  )

  const persistColumnWidths = useCallback(
    async (
      widths: Partial<
        Record<PartnerProjectsSpreadsheetColumnWidthKey, number>
      >,
    ) => {
      try {
        const acct = await fetchConsoleAccount()
        const prefs = mergePartnerProjectsColumnWidthsIntoPrefs(
          (acct.prefs || {}) as UserPrefs,
          widths,
        )
        const updatedAccount = await updateAccountPrefs(
          prefs,
          'partners-spreadsheet-column-widths',
        )
        syncConsoleAccountAfterMutation(queryClient, {
          apiResult: updatedAccount,
        })
      } catch {
        /* preference save is best-effort */
      }
    },
    [queryClient],
  )

  const handleResizePointerDown = useCallback(
    (columnKey: PartnerProjectsSpreadsheetColumnWidthKey) =>
      (event: ReactPointerEvent<HTMLButtonElement>) => {
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
          const resizedWidth = columnWidthsRef.current[columnKey]
          if (typeof resizedWidth === 'number' && Number.isFinite(resizedWidth)) {
            void persistColumnWidths({ [columnKey]: resizedWidth })
          }
        }
        window.addEventListener('pointermove', onMove)
        window.addEventListener('pointerup', onUp)
        window.addEventListener('pointercancel', onUp)
      },
    [applyDraggedWidthPx, getColumnWidthPx, persistColumnWidths],
  )

  const columnResizeLayoutKey = visibleKeys.join('\u0001')

  useLayoutEffect(() => {
    repositionRails()
  }, [columnResizeLayoutKey, columnWidths, repositionRails])

  useLayoutEffect(() => {
    const scroll = scrollRef.current
    const layer = tableLayerRef.current
    if (!scroll || !layer) return

    let frame = 0
    const measure = () => {
      if (frame !== 0) return
      frame = window.requestAnimationFrame(() => {
        frame = 0
        repositionRails()
      })
    }

    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(scroll)
    scroll.addEventListener('scroll', measure, { passive: true })
    window.addEventListener('resize', measure)
    return () => {
      if (frame !== 0) window.cancelAnimationFrame(frame)
      ro.disconnect()
      scroll.removeEventListener('scroll', measure)
      window.removeEventListener('resize', measure)
    }
  }, [columnResizeLayoutKey, columnWidths, repositionRails, scrollRef])

  return {
    visibleKeys,
    tableLayerRef,
    tableRef,
    headerThRefs,
    colRefs,
    railRefs,
    getColumnWidthPx,
    tableWidthPx,
    resizingColumnKey,
    handleResizePointerDown,
  }
}
