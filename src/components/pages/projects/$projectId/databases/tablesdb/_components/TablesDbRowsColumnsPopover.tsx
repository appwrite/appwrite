import { useCallback, useEffect, useMemo, useState } from 'react'
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

function reorderList<T>(list: T[], fromIndex: number, toIndex: number): T[] {
  const copy = [...list]
  const [removed] = copy.splice(fromIndex, 1)
  copy.splice(toIndex, 0, removed)
  return copy
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

function initVisibleHidden(
  saved: string[] | null,
  schemaKeys: string[],
  leading: string[],
  trailing: readonly string[],
): { visibleOrdered: string[]; hidden: string[] } {
  const allKeys = buildAllKeys(schemaKeys, leading, trailing)
  const allKeysSet = new Set(allKeys)

  if (!allKeys.length) {
    return { visibleOrdered: [], hidden: [] }
  }

  if (!saved?.length) {
    return { visibleOrdered: [...allKeys], hidden: [] }
  }

  const hasSystemInSaved = saved.some((k) => k.startsWith('$'))

  if (!hasSystemInSaved) {
    const schemaSet = new Set(schemaKeys)
    const attrVis = saved.filter((k) => schemaSet.has(k))
    const visibleOrdered = [...leading, ...attrVis, ...trailing]
    const visSet = new Set(visibleOrdered)
    const hidden = allKeys.filter((k) => !visSet.has(k))
    return {
      visibleOrdered: visibleOrdered.length > 0 ? visibleOrdered : [...allKeys],
      hidden,
    }
  }

  const seen = new Set<string>()
  const visibleOrdered = saved.filter((k) => {
    if (!allKeysSet.has(k) || seen.has(k)) return false
    seen.add(k)
    return true
  })

  if (visibleOrdered.length === 0) {
    return { visibleOrdered: [...allKeys], hidden: [] }
  }

  const visSet = new Set(visibleOrdered)
  const hidden = allKeys.filter((k) => !visSet.has(k))
  return { visibleOrdered, hidden }
}

function shouldClearPrefs(
  visibleOrdered: string[],
  schemaKeys: string[],
  leading: string[],
  trailing: readonly string[],
): boolean {
  const def = buildAllKeys(schemaKeys, leading, trailing)
  if (visibleOrdered.length !== def.length) return false
  return visibleOrdered.every((k, i) => k === def[i])
}

function effectiveVisibleOrderedFromSaved(
  saved: string[] | null,
  schemaKeys: string[],
  leading: string[],
  trailing: readonly string[],
): string[] {
  return initVisibleHidden(saved, schemaKeys, leading, trailing).visibleOrdered
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
  const [open, setOpen] = useState(false)
  const [visibleOrdered, setVisibleOrdered] = useState<string[]>([])
  const [hidden, setHidden] = useState<string[]>([])
  const [dragOverIndex, setDragOverIndex] = useState<number | null>(null)

  const { columns: apiColumns, isLoading: columnsLoading } =
    useProjectTableColumns(projectId, databaseId, tableId)

  const { savedAttrKeys, persistAttrKeys, isPersisting } =
    useTablesDbRowsListColumns(databaseId, tableId, account)

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

  useEffect(() => {
    if (!open) return
    const { visibleOrdered: v, hidden: h } = initVisibleHidden(
      savedAttrKeys,
      schemaKeys,
      leadingSystemKeys,
      TRAILING_SYSTEM_KEYS,
    )
    setVisibleOrdered(v)
    setHidden(h)
    setDragOverIndex(null)
  }, [open, savedAttrKeys, schemaKeys, leadingSystemKeys])

  const handleDragStart = (e: React.DragEvent, index: number) => {
    if ((e.target as HTMLElement).closest('button')) {
      e.preventDefault()
      return
    }
    e.dataTransfer.setData('application/json', JSON.stringify({ index }))
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.dropEffect = 'move'
    if (e.currentTarget instanceof HTMLElement) {
      e.dataTransfer.setDragImage(e.currentTarget, 0, 0)
    }
  }

  const handleDrop = (e: React.DragEvent, dropIndex: number) => {
    e.preventDefault()
    setDragOverIndex(null)
    const raw = e.dataTransfer.getData('application/json')
    if (!raw) return
    try {
      const { index: dragIndex } = JSON.parse(raw) as { index: number }
      if (typeof dragIndex !== 'number' || dragIndex === dropIndex) return
      setVisibleOrdered((prev) => reorderList(prev, dragIndex, dropIndex))
    } catch {
      /* ignore */
    }
  }

  const hideColumn = useCallback((key: string) => {
    setVisibleOrdered((v) => {
      if (v.length <= 1) return v
      return v.filter((k) => k !== key)
    })
    setHidden((h) => (h.includes(key) ? h : [...h, key]))
  }, [])

  const showColumn = useCallback((key: string) => {
    setHidden((h) => h.filter((k) => k !== key))
    setVisibleOrdered((v) => (v.includes(key) ? v : [...v, key]))
  }, [])

  const handleReset = () => {
    setVisibleOrdered([...allKeys])
    setHidden([])
  }

  const handleApply = async () => {
    if (visibleOrdered.length === 0) return
    try {
      if (
        shouldClearPrefs(
          visibleOrdered,
          schemaKeys,
          leadingSystemKeys,
          TRAILING_SYSTEM_KEYS,
        )
      ) {
        await persistAttrKeys(null)
      } else {
        await persistAttrKeys([...visibleOrdered])
      }
      setOpen(false)
    } catch {
      /* toast optional */
    }
  }

  const applyDisabled =
    columnsLoading ||
    isPersisting ||
    allKeys.length === 0 ||
    visibleOrdered.length === 0

  const effectiveSavedVisible = useMemo(
    () =>
      effectiveVisibleOrderedFromSaved(
        savedAttrKeys,
        schemaKeys,
        leadingSystemKeys,
        TRAILING_SYSTEM_KEYS,
      ),
    [savedAttrKeys, schemaKeys, leadingSystemKeys],
  )

  const hasColumnCustomization = useMemo(() => {
    if (schemaKeys.length === 0 || allKeys.length === 0) return false
    return !shouldClearPrefs(
      effectiveSavedVisible,
      schemaKeys,
      leadingSystemKeys,
      TRAILING_SYSTEM_KEYS,
    )
  }, [
    effectiveSavedVisible,
    schemaKeys,
    leadingSystemKeys,
    allKeys.length,
  ])

  const visibleColumnBadgeCount = effectiveSavedVisible.length

  return (
    <TooltipProvider delayDuration={0}>
      <Popover open={open} onOpenChange={setOpen}>
        <Tooltip>
          <TooltipTrigger asChild>
            <PopoverTrigger asChild>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 shrink-0 gap-1.5 px-2.5 text-[13px]"
                aria-label="Columns"
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
            Show, hide, and reorder table columns
          </TooltipContent>
        </Tooltip>
      <PopoverContent
        className="z-[200] max-h-[calc(100dvh-4rem)] w-[380px] overflow-hidden rounded-xl border-border p-0 shadow-lg"
        align="start"
        side="bottom"
        sideOffset={8}
      >
        <div className="px-4 pt-4 pb-3">
          <h3 className="text-[15px] font-semibold text-foreground">Columns</h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Configure which columns appear in the grid and the order in which
            they are shown. Drag items in the visible list to reorder them.
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="max-h-[min(52dvh,420px)] overflow-y-auto px-4 py-3 space-y-4">
          {columnsLoading ? (
            <p className="text-[13px] text-muted-foreground">Loading…</p>
          ) : visibleOrdered.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              Select at least one column.
            </p>
          ) : (
            <div className="space-y-1.5">
              {visibleOrdered.map((key, index) => {
                const col = colByKey.get(key)
                const label = col ? getColumnTitle(col, key) : key
                const isOver = dragOverIndex === index
                const ColumnIcon = getAttributeColumnIcon(col)
                return (
                  <div
                    key={key}
                    draggable
                    onDragStart={(e) => handleDragStart(e, index)}
                    onDragOver={(e) => {
                      e.preventDefault()
                      setDragOverIndex(index)
                    }}
                    onDragLeave={() => setDragOverIndex(null)}
                    onDrop={(e) => handleDrop(e, index)}
                    className={cn(
                      'group flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-2 py-1.5 transition-colors cursor-grab active:cursor-grabbing',
                      isOver && 'border-primary bg-primary/10',
                    )}
                    aria-label={`${label}, drag to reorder`}
                  >
                    <GripVertical className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <ColumnIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span
                      className={cn(
                        'min-w-0 flex-1 truncate text-[13px] text-foreground',
                        key.startsWith('$') && 'font-mono',
                      )}
                    >
                      {label}
                    </span>
                    <Checkbox
                      checked
                      onCheckedChange={(v) => {
                        if (v === false) hideColumn(key)
                      }}
                      disabled={visibleOrdered.length <= 1}
                      className="shrink-0"
                      aria-label={`Hide ${label}`}
                      onClick={(ev) => ev.stopPropagation()}
                    />
                  </div>
                )
              })}
            </div>
          )}
          {hidden.length > 0 ? (
            <div>
              <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider mb-2">
                Hidden
              </p>
              <div className="space-y-1.5">
                {hidden.map((key) => {
                  const col = colByKey.get(key)
                  const label = col ? getColumnTitle(col, key) : key
                  const ColumnIcon = getAttributeColumnIcon(col)
                  return (
                    <div
                      key={key}
                      className="flex items-center gap-2 rounded-lg border border-border bg-background px-2 py-1.5"
                    >
                      <span
                        className="inline-flex w-3.5 shrink-0 justify-center"
                        aria-hidden
                      />
                      <ColumnIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span
                        className={cn(
                          'min-w-0 flex-1 truncate text-[13px] text-muted-foreground',
                          key.startsWith('$') && 'font-mono',
                        )}
                      >
                        {label}
                      </span>
                      <Checkbox
                        checked={false}
                        onCheckedChange={(v) => {
                          if (v === true) showColumn(key)
                        }}
                        className="shrink-0"
                        aria-label={`Show ${label}`}
                      />
                    </div>
                  )
                })}
              </div>
            </div>
          ) : null}
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
            Reset
          </Button>
          <Button
            type="button"
            size="sm"
            className="h-9 text-[13px]"
            disabled={applyDisabled}
            onClick={() => void handleApply()}
          >
            Apply
          </Button>
        </div>
      </PopoverContent>
      </Popover>
    </TooltipProvider>
  )
}
