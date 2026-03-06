/**
 * Shared filter popover content: active filters list + "Add condition" form.
 * Used inside FiltersPopover; can also be used with a custom Popover wrapper.
 */

import { useState } from 'react'
import { X } from 'lucide-react'
import {
  buildFilterQueryString,
  buildFilterTagFromCompactKey,
  getOperatorsForType,
  SIZE_FILTER_UNITS,
  sizeFilterToBytes,
} from '@/lib/table-filters'
import type { CompactFilterKey, FilterColumn, FilterMap } from '@/lib/table-filters'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

function toDatetimeLocal(iso: string): string {
  if (!iso?.trim()) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export interface FiltersPopoverContentProps {
  columns: FilterColumn[]
  filterMap: FilterMap
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAll: () => void
  onApplyFilter: (key: CompactFilterKey, queryStr: string) => void
  /** Called after applying a filter or clearing all (e.g. close popover). */
  onClose?: () => void
  /** e.g. "buckets", "files" – used in description. */
  resourceLabel?: string
}

export function FiltersPopoverContent({
  columns,
  filterMap,
  onRemoveFilter,
  onClearAll,
  onApplyFilter,
  onClose,
  resourceLabel = 'items',
}: FiltersPopoverContentProps) {
  const [filterColumnId, setFilterColumnId] = useState<string>('')
  const [filterOperatorKey, setFilterOperatorKey] = useState<string>('')
  const [filterValue, setFilterValue] = useState<string>('')
  const [filterValueEnd, setFilterValueEnd] = useState<string>('')
  const [filterSizeUnit, setFilterSizeUnit] = useState<string>('mb')

  const filterEntries = Array.from(filterMap.entries())

  const applyFilter = () => {
    const col = columns.find((c) => c.id === filterColumnId)
    if (!col || !filterOperatorKey) return
    const op = getOperatorsForType(col.type).find((o) => o.key === filterOperatorKey)
    if (!op) return
    const isBetweenOp =
      filterOperatorKey === 'between' || filterOperatorKey === 'notBetween'
    let val: string | number | boolean | undefined = op.noValue
      ? undefined
      : isBetweenOp
        ? `${filterValue.trim()},${filterValueEnd.trim()}`
        : filterValue.trim() || undefined
    if (val !== undefined && val !== '' && !isBetweenOp) {
      if (col.format === 'size') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : sizeFilterToBytes(n, filterSizeUnit)
      } else if (col.type === 'integer') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (col.type === 'double') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (col.type === 'boolean') {
        val = val === 'true'
      } else if (col.id === 'status' && (val === 'enabled' || val === 'disabled')) {
        // Appwrite user status is boolean: true = enabled, false = disabled
        val = val === 'enabled'
      }
    }
    if (isBetweenOp && val !== undefined && col.format === 'size') {
      const [a, b] = `${val}`.split(',').map((s) => s.trim())
      const numA = Number(a)
      const numB = Number(b)
      val =
        !Number.isNaN(numA) && !Number.isNaN(numB)
          ? `${sizeFilterToBytes(numA, filterSizeUnit)},${sizeFilterToBytes(numB, filterSizeUnit)}`
          : val
    }
    const queryString = buildFilterQueryString(
      filterOperatorKey,
      filterColumnId,
      val,
    )
    const compactKey: CompactFilterKey = {
      c: filterColumnId,
      o: filterOperatorKey,
      ...(val !== undefined && val !== '' ? { v: val } : {}),
    }
    onApplyFilter(compactKey, queryString)
    setFilterValue('')
    setFilterValueEnd('')
    onClose?.()
  }

  const handleClearAll = () => {
    onClearAll()
    onClose?.()
  }

  const col = columns.find((c) => c.id === filterColumnId)
  const op = col
    ? getOperatorsForType(col.type).find((o) => o.key === filterOperatorKey)
    : null
  const needsValue = col && op && !op.noValue
  const isBetweenOp =
    filterOperatorKey === 'between' || filterOperatorKey === 'notBetween'
  const isApplyDisabled =
    !filterColumnId ||
    !filterOperatorKey ||
    (needsValue
      ? isBetweenOp
        ? !filterValue.trim() || !filterValueEnd.trim()
        : !filterValue.trim()
      : false)

  const inputClass = 'h-9 w-full text-[13px]'
  const labelClass = 'text-[12px] text-muted-foreground mb-1.5 block'
  const subLabelClass = 'text-[11px] text-muted-foreground mb-1 block'

  const renderValueInput = () => {
    if (!col || !op || op.noValue) return null
    const label = <label className={labelClass}>Value</label>
    if (isBetweenOp) {
      if (col.format === 'size') {
        return (
          <div key="value-between-size" className="space-y-3">
            <div className="grid grid-cols-2 gap-2">
              <div>
                <span className={subLabelClass}>Start</span>
                <Input
                  type="number"
                  min={0}
                  step={1}
                  className={inputClass}
                  value={filterValue}
                  onChange={(e) => setFilterValue(e.target.value)}
                  placeholder="Min"
                />
              </div>
              <div>
                <span className={subLabelClass}>End</span>
                <Input
                  type="number"
                  min={0}
                  step={1}
                  className={inputClass}
                  value={filterValueEnd}
                  onChange={(e) => setFilterValueEnd(e.target.value)}
                  placeholder="Max"
                />
              </div>
            </div>
            <div>
              <span className={subLabelClass}>Unit</span>
              <Select value={filterSizeUnit} onValueChange={setFilterSizeUnit}>
                <SelectTrigger className="h-9 w-full text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent className="z-[200]">
                  {SIZE_FILTER_UNITS.map((u) => (
                    <SelectItem key={u.value} value={u.value}>
                      {u.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
        )
      }
      if (col.type === 'datetime') {
        return (
          <div key="value-between-datetime" className="space-y-3">
            <div>
              <span className={subLabelClass}>Start</span>
              <Input
                type="datetime-local"
                className={inputClass}
                value={toDatetimeLocal(filterValue)}
                onChange={(e) => {
                  const v = e.target.value
                  setFilterValue(v ? new Date(v).toISOString() : '')
                }}
              />
            </div>
            <div>
              <span className={subLabelClass}>End</span>
              <Input
                type="datetime-local"
                className={inputClass}
                value={toDatetimeLocal(filterValueEnd)}
                onChange={(e) => {
                  const v = e.target.value
                  setFilterValueEnd(v ? new Date(v).toISOString() : '')
                }}
              />
            </div>
          </div>
        )
      }
      if (col.type === 'integer' || col.type === 'double') {
        return (
          <div key="value-between-number" className="space-y-3">
            <div>
              <span className={subLabelClass}>Start</span>
              <Input
                type="number"
                step={col.type === 'integer' ? 1 : 'any'}
                className={inputClass}
                value={filterValue}
                onChange={(e) => setFilterValue(e.target.value)}
                placeholder="Min"
              />
            </div>
            <div>
              <span className={subLabelClass}>End</span>
              <Input
                type="number"
                step={col.type === 'integer' ? 1 : 'any'}
                className={inputClass}
                value={filterValueEnd}
                onChange={(e) => setFilterValueEnd(e.target.value)}
                placeholder="Max"
              />
            </div>
          </div>
        )
      }
      return null
    }
    if (col.type === 'boolean') {
      return (
        <div key="value-bool">
          {label}
          <Select value={filterValue} onValueChange={setFilterValue}>
            <SelectTrigger className={inputClass}>
              <SelectValue placeholder="Select" />
            </SelectTrigger>
            <SelectContent className="z-[200]">
              <SelectItem value="true" className="text-[13px]">
                Enabled
              </SelectItem>
              <SelectItem value="false" className="text-[13px]">
                Disabled
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
      )
    }
    if (col.type === 'datetime') {
      return (
        <div key="value-datetime">
          {label}
          <Input
            type="datetime-local"
            className={inputClass}
            value={toDatetimeLocal(filterValue)}
            onChange={(e) => {
              const v = e.target.value
              setFilterValue(v ? new Date(v).toISOString() : '')
            }}
          />
        </div>
      )
    }
    if (col.format === 'size') {
      return (
        <div key="value-size" className="space-y-2">
          {label}
          <div className="flex gap-2">
            <Input
              type="number"
              min={0}
              step={1}
              className={inputClass}
              value={filterValue}
              onChange={(e) => setFilterValue(e.target.value)}
              placeholder="Amount"
            />
            <Select value={filterSizeUnit} onValueChange={setFilterSizeUnit}>
              <SelectTrigger className="h-9 w-full min-w-[100px] text-[13px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent className="z-[200]">
                {SIZE_FILTER_UNITS.map((u) => (
                  <SelectItem key={u.value} value={u.value}>
                    {u.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      )
    }
    if (col.type === 'integer') {
      return (
        <div key="value-int">
          {label}
          <Input
            type="number"
            step={1}
            className={inputClass}
            value={filterValue}
            onChange={(e) => setFilterValue(e.target.value)}
            placeholder="Number"
          />
        </div>
      )
    }
    if (col.type === 'enum' && col.elements?.length) {
      return (
        <div key="value-enum">
          {label}
          <Select value={filterValue} onValueChange={setFilterValue}>
            <SelectTrigger className={inputClass}>
              <SelectValue placeholder="Select" />
            </SelectTrigger>
            <SelectContent className="z-[200]">
              {col.elements.map((el) => (
                <SelectItem
                  key={String(el.value)}
                  value={String(el.value)}
                  className="text-[13px]"
                >
                  {el.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )
    }
    const searchOrNotSearch =
      filterOperatorKey === 'search' || filterOperatorKey === 'notSearch'
    const placeholder = searchOrNotSearch
      ? 'Min. 3 characters'
      : filterOperatorKey === 'regex'
        ? 'e.g. ^foo.*bar$'
        : 'Value'
    return (
      <div key="value-text">
        {label}
        <Input
          className={inputClass}
          value={filterValue}
          onChange={(e) => setFilterValue(e.target.value)}
          placeholder={placeholder}
        />
      </div>
    )
  }

  return (
    <>
      {filterMap.size > 0 && (
        <>
          <div className="border-b border-border px-3 py-1.5">
            <p className="text-[11px] font-medium text-foreground">
              Active filters
            </p>
          </div>
          <div className="max-h-40 overflow-y-auto space-y-1 p-2">
            {filterEntries.map(([key, _queryStr]) => {
              const tag = buildFilterTagFromCompactKey(key, columns)
              const tagLabel = tag.tag.replace(/\*\*(.*?)\*\*/g, '$1')
              return (
                <div
                  key={`${key.c}-${key.o}-${JSON.stringify(key.v ?? '')}`}
                  className="flex select-none items-center justify-between gap-2 rounded-md bg-muted/50 px-1.5 py-1"
                >
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span className="truncate text-[11px] text-foreground">
                        {tagLabel}
                      </span>
                    </TooltipTrigger>
                    <TooltipContent>
                      <p className="text-xs">{tagLabel}</p>
                    </TooltipContent>
                  </Tooltip>
                  <button
                    type="button"
                    onClick={() => onRemoveFilter(key)}
                    className="shrink-0 cursor-pointer rounded p-0.5 hover:bg-muted"
                    aria-label="Remove filter"
                  >
                    <X className="h-2.5 w-2.5" />
                  </button>
                </div>
              )
            })}
          </div>
          <div className="border-t border-border flex justify-start p-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 text-[11px]"
              onClick={handleClearAll}
            >
              Clear all
            </Button>
          </div>
        </>
      )}
      <div
        className={
          filterMap.size > 0
            ? 'border-t border-border px-4 pt-4 pb-2'
            : 'px-4 pt-4 pb-2'
        }
      >
        <h3 className="text-[13px] font-semibold text-foreground">
          Add condition
        </h3>
        <p className="text-[12px] text-muted-foreground mt-1">
          Filter {resourceLabel} by column, operator and value.
        </p>
      </div>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          applyFilter()
        }}
        className="contents"
      >
        <div className="border-t border-border px-4 py-3 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>Column</label>
              <Select
                value={filterColumnId}
                onValueChange={(v) => {
                  setFilterColumnId(v)
                  setFilterValue('')
                  setFilterValueEnd('')
                  const column = columns.find((c) => c.id === v)
                  if (column?.format === 'size') setFilterSizeUnit('mb')
                  const firstOp = column
                    ? getOperatorsForType(column.type)[0]
                    : null
                  setFilterOperatorKey(firstOp?.key ?? '')
                }}
              >
                <SelectTrigger className="h-9 w-full text-[13px]">
                  <SelectValue placeholder="Column" />
                </SelectTrigger>
                <SelectContent className="z-[200]">
                  {columns.map((c) => (
                    <SelectItem key={c.id} value={c.id} className="text-[13px]">
                      {c.title}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className={labelClass}>Operator</label>
              <Select
                value={filterOperatorKey}
                onValueChange={(v) => {
                  setFilterOperatorKey(v)
                  setFilterValueEnd('')
                }}
                disabled={!filterColumnId}
              >
                <SelectTrigger className="h-9 w-full text-[13px]">
                  <SelectValue placeholder="Operator" />
                </SelectTrigger>
                <SelectContent className="z-[200]">
                  {(filterColumnId
                    ? getOperatorsForType(
                        columns.find((c) => c.id === filterColumnId)!.type,
                      )
                    : []
                  ).map((op) => (
                    <SelectItem
                      key={op.key}
                      value={op.key}
                      className="text-[13px]"
                    >
                      {op.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          {filterColumnId && renderValueInput()}
        </div>
        <div className="border-t border-border px-4 py-3 flex flex-wrap items-center gap-2 bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isApplyDisabled}
          >
            Add condition
          </Button>
        </div>
      </form>
    </>
  )
}
