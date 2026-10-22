import {
  Fragment,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import { Columns2, GripVertical } from 'lucide-react'
import { getColumnIcon } from '@/lib/utils/column-icons'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { ToolbarCountBadge } from '@/components/global/shared/ToolbarCountBadge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import {
  useProjectTableColumns,
  useTablesDbRowsListColumns,
} from '@/lib/react-query/hooks'
import {
  isDefaultTablesDbRowsListColumnLayout,
  parseTablesDbRowsListColumnLayout,
  parseTablesDbRowsListColumnsFromPrefs,
  readTablesDbRowsListColumnsRawFromPrefs,
  serializeTablesDbRowsListColumnLayout,
} from '@/lib/user-prefs-keys'
import { useT } from '@/lib/i18n/translate'

function reorderList<T>(list: T[], fromIndex: number, toIndex: number): T[] {
  const copy = [...list]
  const [removed] = copy.splice(fromIndex, 1)
  copy.splice(toIndex, 0, removed)
  return copy
}

type ColumnDropPosition = 'before' | 'after'

type ColumnDropIndicator = {
  index: number
  position: ColumnDropPosition
}

const DRAG_SCROLL_EDGE_PX = 40
const DRAG_SCROLL_MAX_STEP_PX = 14

function reorderWithDropIndicator<T>(
  list: T[],
  dragIndex: number,
  indicator: ColumnDropIndicator,
): T[] {
  let insertIndex =
    indicator.position === 'before' ? indicator.index : indicator.index + 1
  if (dragIndex < insertIndex) insertIndex -= 1
  if (dragIndex === insertIndex) return list
  return reorderList(list, dragIndex, insertIndex)
}

function resolveDropIndicatorFromPointer(
  container: HTMLElement,
  clientY: number,
): ColumnDropIndicator | null {
  const rows = container.querySelectorAll<HTMLElement>('[data-column-row]')
  if (!rows.length) return null

  const firstRect = rows[0].getBoundingClientRect()
  if (clientY < firstRect.top) {
    return { index: 0, position: 'before' }
  }

  for (let i = 0; i < rows.length; i++) {
    const rect = rows[i].getBoundingClientRect()
    if (clientY > rect.bottom) continue
    const position: ColumnDropPosition =
      clientY - rect.top < rect.height / 2 ? 'before' : 'after'
    return { index: i, position }
  }

  return { index: rows.length - 1, position: 'after' }
}

function getColumnKey(col: unknown): string | null {
  const c = col as { key?: string; name?: string; $id?: string }
  const k = c.key || c.name || c.$id
  if (typeof k !== 'string' || !k || k.startsWith('$')) return null
  return k
}

function getColumnTitle(col: unknown, key: string): string {
  const c = col as { title?: string; name?: string }
  const t = c.title || c.name
  if (typeof t === 'string' && t.trim()) return t.trim()
  return key
}

function getAttributeColumnIcon(col: unknown | undefined) {
  const t = (col as { type?: string } | undefined)?.type
  return getColumnIcon(typeof t === 'string' && t.length > 0 ? t : 'string')
}

const TRAILING_SYSTEM_KEYS = ['$createdAt', '$updatedAt'] as const

function systemKeysLeading(includeSequence: boolean): string[] {
  return includeSequence ? ['$sequence', '$id'] : ['$id']
}

function buildAllKeys(
  schemaKeys: string[],
  leading: string[],
  trailing: readonly string[],
): string[] {
  const seen = new Set<string>()
  const out: string[] = []
  for (const k of leading) {
    if (!seen.has(k)) {
      seen.add(k)
      out.push(k)
    }
  }
  for (const k of schemaKeys) {
    if (!seen.has(k)) {
      seen.add(k)
      out.push(k)
    }
  }
  for (const k of trailing) {
    if (!seen.has(k)) {
      seen.add(k)
      out.push(k)
    }
  }
  return out
}

export interface TablesDbRowsColumnsPopoverProps {
  projectId: string
  databaseId: string
  tableId: string
  account: { prefs?: Record<string, unknown> } | undefined
  /** When false, $sequence is omitted from the column list and grid */
  includeSequenceColumn?: boolean
}

export function TablesDbRowsColumnsPopover({
  projectId,
  databaseId,
  tableId,
  account,
  includeSequenceColumn = true,
}: TablesDbRowsColumnsPopoverProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [orderedKeys, setOrderedKeys] = useState<string[]>([])
  const [hiddenKeys, setHiddenKeys] = useState<Set<string>>(() => new Set())
  const [draggingIndex, setDraggingIndex] = useState<number | null>(null)
  const [dropIndicator, setDropIndicator] = useState<ColumnDropIndicator | null>(
    null,
  )
  const listScrollRef = useRef<HTMLDivElement>(null)
  const columnsTriggerRef = useRef<HTMLButtonElement>(null)
  const suppressTooltipTimeoutRef = useRef<number | null>(null)
  const dragPointerYRef = useRef<number | null>(null)
  const dragScrollRafRef = useRef<number | null>(null)
  const [tooltipOpen, setTooltipOpen] = useState(false)
  const [suppressTooltip, setSuppressTooltip] = useState(false)

  const { columns: apiColumns, isLoading: columnsLoading } =
    useProjectTableColumns(projectId, databaseId, 'tablesdb', tableId)

  const { persistAttrKeys, isPersisting } = useTablesDbRowsListColumns(
    databaseId,
    tableId,
    account,
  )

  const leadingSystemKeys = useMemo(
    () => systemKeysLeading(includeSequenceColumn),
    [includeSequenceColumn],
  )

  const schemaKeys = useMemo(() => {
    const keys: string[] = []
    const seen = new Set<string>()
    for (const col of apiColumns) {
      const k = getColumnKey(col)
      if (!k || seen.has(k)) continue
      seen.add(k)
      keys.push(k)
    }
    return keys
  }, [apiColumns])

  const allKeys = useMemo(
    () => buildAllKeys(schemaKeys, leadingSystemKeys, TRAILING_SYSTEM_KEYS),
    [schemaKeys, leadingSystemKeys],
  )

  const colByKey = useMemo(() => {
    const m = new Map<string, unknown>()
    for (const key of leadingSystemKeys) {
      const type =
        key === '$sequence'
          ? 'integer'
          : key === '$id'
            ? 'string'
            : 'datetime'
      m.set(key, { key, type, title: key })
    }
    for (const key of TRAILING_SYSTEM_KEYS) {
      m.set(key, { key, type: 'datetime', title: key })
    }
    for (const col of apiColumns) {
      const k = getColumnKey(col)
      if (k) m.set(k, col)
    }
    return m
  }, [apiColumns, leadingSystemKeys])

  const savedRaw = useMemo(() => {
    if (!account?.prefs || !databaseId || !tableId) return null
    return readTablesDbRowsListColumnsRawFromPrefs(
      account.prefs,
      databaseId,
      tableId,
    )
  }, [account?.prefs, databaseId, tableId])

  useEffect(() => {
    if (!open) return
    const { orderedKeys: order, hiddenKeys: hidden } = parseTablesDbRowsListColumnLayout(
      savedRaw,
      allKeys,
    )
    setOrderedKeys(order)
    setHiddenKeys(hidden)
    setDraggingIndex(null)
    setDropIndicator(null)
  }, [open, savedRaw, allKeys])

  const stopDragScroll = useCallback(() => {
    dragPointerYRef.current = null
    if (dragScrollRafRef.current != null) {
      cancelAnimationFrame(dragScrollRafRef.current)
      dragScrollRafRef.current = null
    }
  }, [])

  useEffect(() => () => stopDragScroll(), [stopDragScroll])

  useEffect(
    () => () => {
      if (suppressTooltipTimeoutRef.current) {
        clearTimeout(suppressTooltipTimeoutRef.current)
      }
    },
    [],
  )

  const dismissTriggerFocusAndTooltip = useCallback(() => {
    setTooltipOpen(false)
    setSuppressTooltip(true)
    if (suppressTooltipTimeoutRef.current) {
      clearTimeout(suppressTooltipTimeoutRef.current)
    }
    suppressTooltipTimeoutRef.current = window.setTimeout(() => {
      setSuppressTooltip(false)
      suppressTooltipTimeoutRef.current = null
    }, 600)

    if (document.activeElement instanceof HTMLElement) {
      document.activeElement.blur()
    }
    columnsTriggerRef.current?.blur()
  }, [])

  const tickDragScroll = useCallback(() => {
    const el = listScrollRef.current
    const y = dragPointerYRef.current
    if (!el || y == null) {
      dragScrollRafRef.current = null
      return
    }
    const rect = el.getBoundingClientRect()
    if (y < rect.top + DRAG_SCROLL_EDGE_PX) {
      const intensity = 1 - (y - rect.top) / DRAG_SCROLL_EDGE_PX
      el.scrollTop -= Math.ceil(DRAG_SCROLL_MAX_STEP_PX * intensity)
    } else if (y > rect.bottom - DRAG_SCROLL_EDGE_PX) {
      const intensity = 1 - (rect.bottom - y) / DRAG_SCROLL_EDGE_PX
      el.scrollTop += Math.ceil(DRAG_SCROLL_MAX_STEP_PX * intensity)
    }
    dragScrollRafRef.current = requestAnimationFrame(tickDragScroll)
  }, [])

  const updateDragPointer = useCallback(
    (clientY: number) => {
      dragPointerYRef.current = clientY
      if (dragScrollRafRef.current == null) {
        dragScrollRafRef.current = requestAnimationFrame(tickDragScroll)
      }
    },
    [tickDragScroll],
  )

  const updateDropIndicatorFromPointer = useCallback((clientY: number) => {
    const container = listScrollRef.current
    if (!container) return
    const next = resolveDropIndicatorFromPointer(container, clientY)
    setDropIndicator(next)
  }, [])

  const clearDragState = useCallback(() => {
    setDraggingIndex(null)
    setDropIndicator(null)
    stopDragScroll()
  }, [stopDragScroll])

  const visibleCount = useMemo(
    () => orderedKeys.filter((k) => !hiddenKeys.has(k)).length,
    [orderedKeys, hiddenKeys],
  )

  const handleDragStart = (e: React.DragEvent, index: number) => {
    if ((e.target as HTMLElement).closest('button')) {
      e.preventDefault()
      return
    }
    setDraggingIndex(index)
    setDropIndicator(null)
    e.dataTransfer.setData('application/json', JSON.stringify({ index }))
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.dropEffect = 'move'
    if (e.currentTarget instanceof HTMLElement) {
      e.dataTransfer.setDragImage(e.currentTarget, 0, 0)
    }
  }

  const handleListDragOver = (e: React.DragEvent) => {
    if (draggingIndex == null) return
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    updateDragPointer(e.clientY)
    updateDropIndicatorFromPointer(e.clientY)
  }

  const handleListDrop = (e: React.DragEvent) => {
    e.preventDefault()
    const dragIndex = draggingIndex
    const indicator = dropIndicator
    clearDragState()
    if (dragIndex == null || !indicator) return
    setOrderedKeys((prev) => reorderWithDropIndicator(prev, dragIndex, indicator))
  }

  const hideColumn = useCallback(
    (key: string) => {
      if (visibleCount <= 1) return
      setHiddenKeys((prev) => {
        const next = new Set(prev)
        next.add(key)
        return next
      })
    },
    [visibleCount],
  )

  const showColumn = useCallback((key: string) => {
    setHiddenKeys((prev) => {
      const next = new Set(prev)
      next.delete(key)
      return next
    })
  }, [])

  const handleReset = () => {
    setOrderedKeys([...allKeys])
    setHiddenKeys(new Set())
  }

  const handleOpenChange = useCallback(
    (next: boolean) => {
      setOpen(next)
      if (next) {
        setTooltipOpen(false)
        return
      }
      dismissTriggerFocusAndTooltip()
      window.setTimeout(() => columnsTriggerRef.current?.blur(), 0)
    },
    [dismissTriggerFocusAndTooltip],
  )

  const handleTooltipOpenChange = useCallback(
    (next: boolean) => {
      if (open || suppressTooltip) {
        if (!next) setTooltipOpen(false)
        return
      }
      setTooltipOpen(next)
    },
    [open, suppressTooltip],
  )

  const handleApply = async () => {
    if (visibleCount === 0) return
    const payload = isDefaultTablesDbRowsListColumnLayout(
      orderedKeys,
      hiddenKeys,
      allKeys,
    )
      ? null
      : serializeTablesDbRowsListColumnLayout(orderedKeys, hiddenKeys)

    handleOpenChange(false)

    try {
      await persistAttrKeys(payload)
      dismissTriggerFocusAndTooltip()
    } catch {
      /* toast optional */
    }
  }

  const applyDisabled =
    columnsLoading ||
    isPersisting ||
    allKeys.length === 0 ||
    visibleCount === 0

  const effectiveSavedVisible = useMemo(
    () =>
      parseTablesDbRowsListColumnsFromPrefs(
        account?.prefs,
        databaseId,
        tableId,
      ),
    [account?.prefs, databaseId, tableId],
  )

  const hasColumnCustomization = useMemo(() => {
    if (schemaKeys.length === 0 || allKeys.length === 0) return false
    if (!savedRaw?.length) return false
    const saved = parseTablesDbRowsListColumnLayout(savedRaw, allKeys)
    return !isDefaultTablesDbRowsListColumnLayout(
      saved.orderedKeys,
      saved.hiddenKeys,
      allKeys,
    )
  }, [savedRaw, schemaKeys, allKeys.length])

  const visibleColumnBadgeCount = effectiveSavedVisible?.length ?? allKeys.length

  return (
    <TooltipProvider delayDuration={0}>
      <Popover open={open} onOpenChange={handleOpenChange}>
        <Tooltip
          open={tooltipOpen && !open && !suppressTooltip}
          onOpenChange={handleTooltipOpenChange}
        >
          <TooltipTrigger asChild>
            <PopoverTrigger asChild>
              <Button
                ref={columnsTriggerRef}
                type="button"
                variant="outline"
                size="sm"
                className="h-9 shrink-0 gap-1.5 px-2.5 text-[13px]"
                aria-label={t('Columns')}
              >
                <Columns2 className="h-3.5 w-3.5 shrink-0" />
                {hasColumnCustomization ? (
                  <ToolbarCountBadge
                    count={visibleColumnBadgeCount}
                    placement="inline"
                  />
                ) : null}
              </Button>
            </PopoverTrigger>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            {t('Show, hide, and reorder table columns')}
          </TooltipContent>
        </Tooltip>
      <PopoverContent
        className="max-h-[calc(100dvh-4rem)] w-[380px] overflow-hidden rounded-xl border-border p-0 shadow-lg"
        align="start"
        side="bottom"
        sideOffset={8}
        onCloseAutoFocus={(e) => {
          e.preventDefault()
          dismissTriggerFocusAndTooltip()
        }}
      >
        <div className="px-4 pt-4 pb-3">
          <h3 className="text-[15px] font-semibold text-foreground">{t('Columns')}</h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Configure which columns appear in the grid and their order. Drag to reorder; hidden columns stay in place so you can show them where you want.')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div
          ref={listScrollRef}
          className="max-h-[min(52dvh,420px)] overflow-y-auto px-4 py-3"
          onDragOver={handleListDragOver}
          onDrop={handleListDrop}
          onDragLeave={(e) => {
            const related = e.relatedTarget as Node | null
            if (related && e.currentTarget.contains(related)) return
            setDropIndicator(null)
            stopDragScroll()
          }}
        >
          {columnsLoading ? (
            <p className="text-[13px] text-muted-foreground">{t('Loading…')}</p>
          ) : orderedKeys.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Select at least one column.')}
            </p>
          ) : (
            <div className="space-y-1.5">
              {orderedKeys.map((key, index) => {
                const col = colByKey.get(key)
                const label = col ? getColumnTitle(col, key) : key
                const isHidden = hiddenKeys.has(key)
                const isDragging = draggingIndex === index
                const showInsertBefore =
                  dropIndicator?.index === index &&
                  dropIndicator.position === 'before'
                const showInsertAfter =
                  dropIndicator?.index === index &&
                  dropIndicator.position === 'after'
                const ColumnIcon = getAttributeColumnIcon(col)
                return (
                  <Fragment key={key}>
                    {showInsertBefore ? (
                      <div
                        className="h-0.5 shrink-0 rounded-full bg-primary shadow-[0_0_0_1px_hsl(var(--primary)/0.35)]"
                        role="presentation"
                        aria-hidden
                      />
                    ) : null}
                    <div
                      data-column-row
                      draggable
                      onDragStart={(e) => handleDragStart(e, index)}
                      onDragEnd={clearDragState}
                      className={cn(
                        'group flex items-center gap-2 rounded-lg border border-border px-2 py-1.5 transition-[opacity,background-color] cursor-grab active:cursor-grabbing',
                        isHidden ? 'bg-background' : 'bg-muted/20',
                        isDragging && 'opacity-40',
                      )}
                      aria-label={`${label}, drag to reorder`}
                    >
                    <GripVertical
                      className={cn(
                        'h-3.5 w-3.5 shrink-0',
                        isHidden ? 'text-muted-foreground/60' : 'text-muted-foreground',
                      )}
                    />
                    <ColumnIcon
                      className={cn(
                        'h-3.5 w-3.5 shrink-0',
                        isHidden ? 'text-muted-foreground/60' : 'text-muted-foreground',
                      )}
                    />
                    <span
                      className={cn(
                        'min-w-0 flex-1 truncate text-[13px]',
                        key.startsWith('$') && 'font-mono',
                        isHidden ? 'text-muted-foreground' : 'text-foreground',
                      )}
                    >
                      {label}
                    </span>
                    <Checkbox
                      checked={!isHidden}
                      onCheckedChange={(v) => {
                        if (v === true) showColumn(key)
                        else if (v === false) hideColumn(key)
                      }}
                      disabled={!isHidden && visibleCount <= 1}
                      className="shrink-0"
                      aria-label={isHidden ? `Show ${label}` : `Hide ${label}`}
                      onClick={(ev) => ev.stopPropagation()}
                    />
                    </div>
                    {showInsertAfter ? (
                      <div
                        className="h-0.5 shrink-0 rounded-full bg-primary shadow-[0_0_0_1px_hsl(var(--primary)/0.35)]"
                        role="presentation"
                        aria-hidden
                      />
                    ) : null}
                  </Fragment>
                )
              })}
            </div>
          )}
        </div>
        <div className="border-t border-border" />
        <div className="flex flex-col-reverse gap-2 px-4 py-3 sm:flex-row sm:justify-end bg-muted/30">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleReset}
            disabled={isPersisting || columnsLoading || allKeys.length === 0}
          >
            {t('Reset')}
          </Button>
          <Button
            type="button"
            size="sm"
            className="h-9 text-[13px]"
            disabled={applyDisabled}
            onClick={() => void handleApply()}
          >
            {t('Apply')}
          </Button>
        </div>
      </PopoverContent>
      </Popover>
    </TooltipProvider>
  )
}
