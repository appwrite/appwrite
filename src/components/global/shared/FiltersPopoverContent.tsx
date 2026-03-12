/**
 * Shared filter popover content: active filters list + "Add condition" form.
 * Used inside FiltersPopover; can also be used with a custom Popover wrapper.
 */

import { useEffect, useState } from 'react'
import {
  ArrowDown,
  ArrowUp,
  GripVertical,
  Loader2,
  Plus,
  Trash2,
  X,
} from 'lucide-react'
import {
  buildFilterQueryString,
  buildFilterTagFromCompactKey,
  encodeSort,
  getOperatorsForType,
  SIZE_FILTER_UNITS,
  sizeFilterToBytes,
} from '@/lib/table-filters'
import type {
  CompactFilterKey,
  FilterColumn,
  FilterMap,
} from '@/lib/table-filters'
import { mapToQueryParam } from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useSavedFilters } from '@/lib/react-query/hooks/auth'
import { useOrganizationScopes } from '@/lib/react-query/hooks'
import type { SavedFilter } from '@/lib/user-prefs-keys'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
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

function reorderList<T>(list: T[], fromIndex: number, toIndex: number): T[] {
  const copy = [...list]
  const [removed] = copy.splice(fromIndex, 1)
  copy.splice(toIndex, 0, removed)
  return copy
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
  /** Scope for saved filter presets (e.g. "sites", "storage.buckets"). Enables saved filters section. */
  filterScope?: string
  /** Apply a saved filter (query and optional sort). When sort is supported, pass (queryParam, sortParam). */
  onApplyQuery?: (queryParam: string | undefined, sortParam?: string) => void
  /** Team/org ID for team-level saved filters. When set, users can save filters for the team. */
  teamId?: string | null
  /** Current sort field (e.g. $createdAt). When set with sortOrder and onSortChange, shows "Order by" using columns. */
  sortBy?: string
  /** Current sort direction. */
  sortOrder?: 'asc' | 'desc'
  /** Called when user changes sort. */
  onSortChange?: (sortBy: string, sortOrder: 'asc' | 'desc') => void
  /** Encoded default sort (e.g. $createdAt_desc) for matching saved state. Omit when no sort UI. */
  defaultSortParam?: string
  /** Called when user clicks Reset. Omit to hide the reset control. */
  onReset?: () => void
}

export function FiltersPopoverContent({
  columns,
  filterMap,
  onRemoveFilter,
  onClearAll,
  onApplyFilter,
  onClose,
  resourceLabel: _resourceLabel = 'items',
  filterScope,
  onApplyQuery,
  teamId,
  sortBy,
  sortOrder,
  onSortChange,
  defaultSortParam,
  onReset,
}: FiltersPopoverContentProps) {
  const sortEnabled = sortBy != null && sortOrder != null && !!onSortChange
  const sortOptionsFromColumns = sortEnabled
    ? columns.map((c) => ({ id: c.id, label: c.title }))
    : []
  const { account } = useAuth()
  const {
    userSavedFilters,
    teamSavedFilters,
    savedFilters,
    addSavedFilter,
    deleteSavedFilter,
    reorderSavedFilters,
    updateSavedFilterName,
    isAdding,
    hasTeamLevel,
  } = useSavedFilters(
    filterScope ?? null,
    account as { prefs?: Record<string, unknown> } | undefined,
    teamId ?? null,
  )

  const { access } = useOrganizationScopes(teamId ?? undefined)
  const { features } = useConsoleProfile()
  const canSaveTeamFiltersResult =
    !teamId || canSaveTeamFilters(access, features)

  const [saveName, setSaveName] = useState('')
  const [saveLevel, setSaveLevel] = useState<'user' | 'team'>('user')
  const [savingId, setSavingId] = useState<string | null>(null)
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const [dragOverKey, setDragOverKey] = useState<string | null>(null)
  const [editingFilterKey, setEditingFilterKey] = useState<string | null>(null)
  const [editingName, setEditingName] = useState('')

  const firstColumnId = columns[0]?.id ?? ''
  const firstCol = columns[0]
  const firstOperatorKey = firstColumnId
    ? (getOperatorsForType(firstCol!.type, {
        fulltextSearchable: !!firstCol!.fulltextSearchable,
        enumOptional:
          firstCol!.type === 'enum' ? firstCol!.optional : undefined,
      })[0]?.key ?? '')
    : ''
  const [filterColumnId, setFilterColumnId] = useState<string>(
    () => firstColumnId,
  )
  const [filterOperatorKey, setFilterOperatorKey] = useState<string>(
    () => firstOperatorKey,
  )
  const [filterValue, setFilterValue] = useState<string>('')
  const [filterValueEnd, setFilterValueEnd] = useState<string>('')
  const [filterSizeUnit, setFilterSizeUnit] = useState<string>('mb')

  useEffect(() => {
    if (columns.length > 0 && !filterColumnId) {
      const first = columns[0]
      const ops = getOperatorsForType(first.type, {
        fulltextSearchable: !!first.fulltextSearchable,
        enumOptional: first.type === 'enum' ? first.optional : undefined,
      })
      setFilterColumnId(first.id)
      setFilterOperatorKey(ops[0]?.key ?? '')
    }
  }, [columns, filterColumnId])

  const filterEntries = Array.from(filterMap.entries())

  const applyFilter = () => {
    const col = columns.find((c) => c.id === filterColumnId)
    if (!col || !filterOperatorKey) return
    const op = getOperatorsForType(col.type, {
      fulltextSearchable: !!col.fulltextSearchable,
      enumOptional: col.type === 'enum' ? col.optional : undefined,
    }).find((o) => o.key === filterOperatorKey)
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
        val = Number.isNaN(n)
          ? String(val)
          : sizeFilterToBytes(n, filterSizeUnit)
      } else if (col.type === 'integer') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (col.type === 'double') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (col.type === 'boolean') {
        val = val === 'true'
      } else if (
        col.id === 'status' &&
        (val === 'enabled' || val === 'disabled')
      ) {
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
    // Keep popover open so users can add multiple filters in one go
  }

  const handleClearAll = () => {
    onClearAll()
    onClose?.()
  }

  const col = columns.find((c) => c.id === filterColumnId)
  const op = col
    ? getOperatorsForType(col.type, {
        fulltextSearchable: !!col.fulltextSearchable,
        enumOptional: col.type === 'enum' ? col.optional : undefined,
      }).find((o) => o.key === filterOperatorKey)
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
  const labelClass = 'text-[12px] font-medium text-muted-foreground mb-1 block'
  const subLabelClass = 'text-[11px] text-muted-foreground mb-0.5 block'

  const renderValueInput = () => {
    if (!col || !op || op.noValue) return null
    const label = <label className={labelClass}>Value</label>
    if (isBetweenOp) {
      if (col.format === 'size') {
        return (
          <div key="value-between-size" className="space-y-2">
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
              <SearchableSelect
                value={filterSizeUnit}
                onValueChange={setFilterSizeUnit}
                items={SIZE_FILTER_UNITS.map((u) => ({
                  value: u.value,
                  label: u.label,
                }))}
                placeholder="Unit"
                searchPlaceholder="Search units…"
                emptyMessage="No units found"
              />
            </div>
          </div>
        )
      }
      if (col.type === 'datetime') {
        return (
          <div key="value-between-datetime" className="space-y-2">
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
          <div key="value-between-number" className="space-y-2">
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
          <SearchableSelect
            value={filterValue}
            onValueChange={setFilterValue}
            items={[
              { value: 'true', label: 'Enabled' },
              { value: 'false', label: 'Disabled' },
            ]}
            placeholder="Select"
            searchPlaceholder="Search…"
            emptyMessage="No results"
          />
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
        <div key="value-size" className="space-y-1.5">
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
            <SearchableSelect
              value={filterSizeUnit}
              onValueChange={setFilterSizeUnit}
              items={SIZE_FILTER_UNITS.map((u) => ({
                value: u.value,
                label: u.label,
              }))}
              placeholder="Unit"
              searchPlaceholder="Search units…"
              emptyMessage="No units found"
              triggerClassName="min-w-[100px]"
            />
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
          <SearchableSelect
            value={filterValue}
            onValueChange={setFilterValue}
            items={col.elements.map((el) => ({
              value: String(el.value),
              label: el.label,
            }))}
            placeholder="Select"
            searchPlaceholder="Search values…"
            emptyMessage="No values found"
          />
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

  const hasSavedFiltersFeature = !!(filterScope && onApplyQuery)
  const currentQueryParam = mapToQueryParam(filterMap)
  const currentSortParam = sortEnabled
    ? encodeSort(sortBy!, sortOrder!)
    : undefined
  const matchingSavedFilter = hasSavedFiltersFeature
    ? savedFilters.find(
        (s) =>
          s.query === currentQueryParam &&
          (!sortEnabled ||
            (s.sort ?? defaultSortParam) ===
              (currentSortParam ?? defaultSortParam)),
      )
    : null
  const hasNonDefaultSort =
    sortEnabled &&
    defaultSortParam != null &&
    (currentSortParam ?? defaultSortParam) !== defaultSortParam
  const canSaveCurrent =
    filterMap.size > 0 || (sortEnabled && hasNonDefaultSort)

  const filtersTabContent = (
    <>
      {/* Order by – when list supports sort; options from table columns; saved with filter presets */}
      {sortEnabled && sortOptionsFromColumns.length > 0 && (
        <>
          <div className="px-4 pt-3 pb-2">
            <div className="flex items-center justify-between gap-2 mb-2">
              <p className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                Order by
              </p>
              {onReset && (hasNonDefaultSort || filterMap.size > 0) && (
                <button
                  type="button"
                  onClick={() => {
                    onReset()
                    onClose?.()
                  }}
                  className="cursor-pointer text-[12px] text-muted-foreground hover:text-foreground transition-colors shrink-0"
                >
                  Reset
                </button>
              )}
            </div>
            <div className="flex items-center gap-2">
              <SearchableSelect
                value={sortBy}
                onValueChange={(field) => {
                  const nextOrder =
                    field === sortBy && sortOrder === 'desc' ? 'asc' : 'desc'
                  onSortChange!(field, nextOrder)
                }}
                items={sortOptionsFromColumns.map((o) => ({
                  value: o.id,
                  label: o.label,
                }))}
                placeholder="Column"
                searchPlaceholder="Search columns…"
                emptyMessage="No columns"
                triggerClassName="h-9 min-w-0 flex-1 text-[13px]"
              />
              <div className="flex shrink-0 rounded-md border border-border overflow-hidden">
                <button
                  type="button"
                  onClick={() => onSortChange!(sortBy, 'asc')}
                  className={cn(
                    'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors',
                    sortOrder === 'asc'
                      ? 'bg-muted text-foreground'
                      : 'text-muted-foreground hover:bg-muted/60',
                  )}
                  aria-pressed={sortOrder === 'asc'}
                  title="Ascending"
                >
                  <ArrowUp className="h-3.5 w-3.5" />
                  Asc
                </button>
                <button
                  type="button"
                  onClick={() => onSortChange!(sortBy, 'desc')}
                  className={cn(
                    'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors border-l border-border',
                    sortOrder === 'desc'
                      ? 'bg-muted text-foreground'
                      : 'text-muted-foreground hover:bg-muted/60',
                  )}
                  aria-pressed={sortOrder === 'desc'}
                  title="Descending"
                >
                  <ArrowDown className="h-3.5 w-3.5" />
                  Desc
                </button>
              </div>
            </div>
          </div>
          <div className="border-t border-border" />
        </>
      )}

      <form
        onSubmit={(e) => {
          e.preventDefault()
          applyFilter()
        }}
        className="px-4 py-3"
      >
        <div className="space-y-2">
          <div className="grid grid-cols-2 gap-2">
            <div className="space-y-1">
              <label className={labelClass}>Column</label>
              <SearchableSelect
                value={filterColumnId}
                onValueChange={(v) => {
                  setFilterColumnId(v)
                  setFilterValue('')
                  setFilterValueEnd('')
                  const column = columns.find((c) => c.id === v)
                  if (column?.format === 'size') setFilterSizeUnit('mb')
                  const firstOp = column
                    ? getOperatorsForType(column.type, {
                        fulltextSearchable: !!column.fulltextSearchable,
                        enumOptional:
                          column.type === 'enum' ? column.optional : undefined,
                      })[0]
                    : null
                  setFilterOperatorKey(firstOp?.key ?? '')
                }}
                items={columns.map((c) => ({ value: c.id, label: c.title }))}
                placeholder="Column"
                searchPlaceholder="Search columns…"
                emptyMessage="No columns found"
              />
            </div>
            <div className="space-y-1">
              <label className={labelClass}>Operator</label>
              <SearchableSelect
                value={filterOperatorKey}
                onValueChange={(v) => {
                  setFilterOperatorKey(v)
                  setFilterValueEnd('')
                }}
                disabled={!filterColumnId}
                items={
                  filterColumnId
                    ? (() => {
                        const column = columns.find(
                          (c) => c.id === filterColumnId,
                        )
                        return column
                          ? getOperatorsForType(column.type, {
                              fulltextSearchable: !!column.fulltextSearchable,
                              enumOptional:
                                column.type === 'enum'
                                  ? column.optional
                                  : undefined,
                            }).map((op) => ({ value: op.key, label: op.label }))
                          : []
                      })()
                    : []
                }
                placeholder="Operator"
                searchPlaceholder="Search operators…"
                emptyMessage="No operators found"
              />
            </div>
          </div>
          {filterColumnId && renderValueInput()}
          <Button
            type="submit"
            size="sm"
            className="h-9 w-full text-[13px]"
            disabled={isApplyDisabled}
          >
            Add filter
          </Button>
        </div>
      </form>

      {/* Active filters – below form so new filters appear here */}
      {filterMap.size > 0 && (
        <>
          <div className="border-t border-border" />
          <div className="px-4 py-2">
            <div className="flex items-center justify-between gap-2 mb-1.5">
              <span className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                Active ({filterMap.size})
              </span>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 text-[12px] text-muted-foreground hover:text-foreground -mr-1"
                onClick={handleClearAll}
              >
                Clear all
              </Button>
            </div>
            <div className="max-h-40 overflow-y-auto space-y-1">
              {filterEntries.map(([key, _queryStr]) => {
                const tag = buildFilterTagFromCompactKey(key, columns)
                const parts = tag.tag.split(/\*\*/)
                const column = parts[1] ?? ''
                const operator = (parts[2] ?? '').trim()
                const value = parts[3] ?? null
                return (
                  <div
                    key={`${key.c}-${key.o}-${JSON.stringify(key.v ?? '')}`}
                    className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5 group"
                  >
                    <span className="flex min-w-0 flex-1 items-center gap-1.5 truncate text-[12px]">
                      <span className="shrink-0 font-medium text-foreground">
                        {column}
                      </span>
                      <span className="shrink-0 text-muted-foreground">
                        {operator}
                      </span>
                      {value != null && value !== '' && (
                        <span className="truncate text-foreground">
                          {value}
                        </span>
                      )}
                    </span>
                    <button
                      type="button"
                      onClick={() => onRemoveFilter(key)}
                      className="cursor-pointer shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                      aria-label="Remove filter"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </div>
                )
              })}
            </div>
          </div>
        </>
      )}

      {/* Save current on Filters tab (when saved filters feature is on and there are filters and/or non-default sort) */}
      {hasSavedFiltersFeature && canSaveCurrent && (
        <div className="border-t border-border px-4 py-2">
          {matchingSavedFilter ? (
            <p className="text-[12px] text-muted-foreground">
              Same as saved filter &quot;{matchingSavedFilter.name}&quot;
            </p>
          ) : (
            <div className="space-y-1.5">
              <p className="text-[12px] text-muted-foreground">
                Save for later
              </p>
              <div className="flex flex-nowrap items-center gap-2">
                {hasTeamLevel && (
                  <div className="flex shrink-0 rounded-md border border-border overflow-hidden">
                    <button
                      type="button"
                      onClick={() => setSaveLevel('user')}
                      className={cn(
                        'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors',
                        saveLevel === 'user'
                          ? 'bg-muted text-foreground'
                          : 'text-muted-foreground hover:bg-muted/60',
                      )}
                      aria-pressed={saveLevel === 'user'}
                    >
                      For me
                    </button>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <button
                          type="button"
                          onClick={() =>
                            canSaveTeamFiltersResult && setSaveLevel('team')
                          }
                          disabled={!canSaveTeamFiltersResult}
                          className={cn(
                            'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors border-l border-border',
                            saveLevel === 'team'
                              ? 'bg-muted text-foreground'
                              : 'text-muted-foreground hover:bg-muted/60 disabled:opacity-50',
                          )}
                          aria-pressed={saveLevel === 'team'}
                        >
                          For team
                        </button>
                      </TooltipTrigger>
                      {!canSaveTeamFiltersResult && (
                        <TooltipContent
                          side="top"
                          sideOffset={4}
                          className="z-[250]"
                        >
                          Only owners and developers can save team-level
                          filters.
                        </TooltipContent>
                      )}
                    </Tooltip>
                  </div>
                )}
                <Input
                  placeholder="Filter name"
                  value={saveName}
                  onChange={(e) => setSaveName(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key !== 'Enter') return
                    e.preventDefault()
                    const name = saveName.trim()
                    if (!name || isAdding || savingId !== null) return
                    if (
                      hasTeamLevel &&
                      saveLevel === 'team' &&
                      !canSaveTeamFiltersResult
                    )
                      return
                    setSavingId('current')
                    addSavedFilter({
                      name,
                      query: currentQueryParam,
                      level: hasTeamLevel ? saveLevel : 'user',
                      ...(currentSortParam != null
                        ? { sort: currentSortParam }
                        : {}),
                    })
                      .then(() => setSaveName(''))
                      .finally(() => setSavingId(null))
                  }}
                  className="h-9 min-w-0 flex-1 text-[13px]"
                  maxLength={64}
                />
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">
                      <Button
                        type="button"
                        size="icon"
                        variant="secondary"
                        className="h-9 w-9 shrink-0"
                        title="Save filter"
                        aria-label="Save filter"
                        disabled={
                          !saveName.trim() ||
                          isAdding ||
                          savingId !== null ||
                          (hasTeamLevel &&
                            saveLevel === 'team' &&
                            !canSaveTeamFiltersResult)
                        }
                        onClick={() => {
                          const name = saveName.trim()
                          if (!name) return
                          setSavingId('current')
                          addSavedFilter({
                            name,
                            query: currentQueryParam,
                            level: hasTeamLevel ? saveLevel : 'user',
                            ...(currentSortParam != null
                              ? { sort: currentSortParam }
                              : {}),
                          })
                            .then(() => setSaveName(''))
                            .finally(() => setSavingId(null))
                        }}
                      >
                        {isAdding && savingId === 'current' ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <Plus className="h-4 w-4" />
                        )}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  {hasTeamLevel &&
                    saveLevel === 'team' &&
                    !canSaveTeamFiltersResult && (
                      <TooltipContent
                        side="top"
                        sideOffset={4}
                        className="z-[250]"
                      >
                        Only owners and developers can save team-level filters.
                      </TooltipContent>
                    )}
                </Tooltip>
              </div>
            </div>
          )}
        </div>
      )}
    </>
  )

  const handleSavedFilterDragStart = (
    e: React.DragEvent,
    level: 'user' | 'team',
    index: number,
  ) => {
    if ((e.target as HTMLElement).closest('button')) {
      e.preventDefault()
      return
    }
    e.dataTransfer.setData('application/json', JSON.stringify({ level, index }))
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.dropEffect = 'move'
    if (e.currentTarget instanceof HTMLElement) {
      e.dataTransfer.setDragImage(e.currentTarget, 0, 0)
    }
  }

  const handleSavedFilterDrop = (
    e: React.DragEvent,
    level: 'user' | 'team',
    dropIndex: number,
  ) => {
    e.preventDefault()
    setDragOverKey(null)
    const raw = e.dataTransfer.getData('application/json')
    if (!raw) return
    try {
      const { level: dragLevel, index: dragIndex } = JSON.parse(raw) as {
        level: 'user' | 'team'
        index: number
      }
      if (dragLevel !== level || dragIndex === dropIndex) return
      const list = level === 'user' ? userSavedFilters : teamSavedFilters
      const reordered = reorderList(list as SavedFilter[], dragIndex, dropIndex)
      reorderSavedFilters(reordered, level)
    } catch {
      // ignore invalid payload
    }
  }

  const rowDropKey = (level: 'user' | 'team', index: number) =>
    `${level}-${index}`

  const editKeyFor = (level: 'user' | 'team', id: string) => `${level}-${id}`

  const handleStartEdit = (
    level: 'user' | 'team',
    item: { id: string; name: string },
  ) => {
    setEditingFilterKey(editKeyFor(level, item.id))
    setEditingName(item.name)
  }

  const handleSaveEdit = (
    level: 'user' | 'team',
    item: { id: string; name: string },
  ) => {
    const name = editingName.trim()
    if (name && name !== item.name) {
      updateSavedFilterName(item.id, level, name)
    }
    setEditingFilterKey(null)
  }

  const canEditTeamFilter = (l: 'user' | 'team') =>
    l === 'user' || canSaveTeamFiltersResult

  const renderSavedFilterItem = (
    item: { id: string; name: string; query: string; sort?: string },
    level: 'user' | 'team',
    index: number,
  ) => {
    const key = rowDropKey(level, index)
    const isDragOver = dragOverKey === key
    const isEditing = editingFilterKey === editKeyFor(level, item.id)
    const canEdit = canEditTeamFilter(level)
    return (
      <div
        key={`${level}-${item.id}`}
        draggable={canEdit && !isEditing}
        onDragStart={
          canEdit && !isEditing
            ? (e) => handleSavedFilterDragStart(e, level, index)
            : undefined
        }
        onDragOver={(e) => {
          if (!canEdit || isEditing) return
          e.preventDefault()
          e.dataTransfer.dropEffect = 'move'
          setDragOverKey(key)
        }}
        onDragLeave={() => setDragOverKey(null)}
        onDrop={(e) => {
          e.preventDefault()
          if (canEdit) handleSavedFilterDrop(e, level, index)
        }}
        className={`flex items-center gap-2 rounded-lg border px-2 py-1.5 group transition-colors ${
          isEditing
            ? 'cursor-default border-border bg-muted/20'
            : canEdit
              ? 'cursor-grab active:cursor-grabbing'
              : ''
        } ${isDragOver && canEdit && !isEditing ? 'border-primary bg-primary/10' : 'border-border bg-muted/20'}`}
        aria-label={
          isEditing
            ? undefined
            : canEdit
              ? `${item.name}, drag to reorder`
              : item.name
        }
      >
        {canEdit ? (
          <GripVertical className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        ) : (
          <span className="h-3.5 w-3.5 shrink-0" aria-hidden />
        )}
        {isEditing ? (
          <Input
            autoFocus
            value={editingName}
            onChange={(e) => setEditingName(e.target.value)}
            onBlur={() => handleSaveEdit(level, item)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                handleSaveEdit(level, item)
              }
              if (e.key === 'Escape') {
                setEditingFilterKey(null)
                setEditingName('')
              }
            }}
            className="h-7 flex-1 min-w-0 text-[13px]"
            maxLength={64}
            onClick={(e) => e.stopPropagation()}
          />
        ) : canEdit ? (
          <Tooltip delayDuration={0}>
            <TooltipTrigger asChild>
              <span
                className="flex-1 min-w-0 truncate text-[13px] text-foreground cursor-pointer"
                onDoubleClick={(e) => {
                  e.stopPropagation()
                  handleStartEdit(level, item)
                }}
              >
                {item.name}
              </span>
            </TooltipTrigger>
            <TooltipContent side="top" sideOffset={4}>
              Double-click to rename
            </TooltipContent>
          </Tooltip>
        ) : (
          <span className="flex-1 min-w-0 truncate text-[13px] text-foreground">
            {item.name}
          </span>
        )}
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 text-[12px] shrink-0"
          onClick={(e) => {
            e.stopPropagation()
            onApplyQuery!(item.query || undefined, item.sort)
          }}
        >
          Apply
        </Button>
        {canEdit && (
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation()
              setDeletingId(item.id)
              deleteSavedFilter(item.id, level).finally(() =>
                setDeletingId(null),
              )
            }}
            disabled={deletingId !== null}
            className="cursor-pointer shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive transition-colors disabled:opacity-50"
            aria-label="Delete saved filter"
          >
            {deletingId === item.id ? (
              <Loader2 className="h-3 w-3 animate-spin" />
            ) : (
              <Trash2 className="h-3 w-3" />
            )}
          </button>
        )}
      </div>
    )
  }

  const savedTabContent = hasSavedFiltersFeature && (
    <div>
      {userSavedFilters.length > 0 || teamSavedFilters.length > 0 ? (
        <div className="px-4 py-2">
          <div className="max-h-80 overflow-y-auto space-y-3">
            {hasTeamLevel ? (
              <>
                {userSavedFilters.length > 0 && (
                  <div>
                    <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider mb-1">
                      My filters
                    </p>
                    <div className="space-y-1">
                      {userSavedFilters.map((item, index) =>
                        renderSavedFilterItem(item, 'user', index),
                      )}
                    </div>
                  </div>
                )}
                {teamSavedFilters.length > 0 && (
                  <div>
                    <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider mb-1">
                      Team filters
                    </p>
                    <div className="space-y-1">
                      {teamSavedFilters.map((item, index) =>
                        renderSavedFilterItem(item, 'team', index),
                      )}
                    </div>
                  </div>
                )}
              </>
            ) : (
              <div className="space-y-1">
                {savedFilters.map((item, index) =>
                  renderSavedFilterItem(item, 'user', index),
                )}
              </div>
            )}
          </div>
        </div>
      ) : null}
      {userSavedFilters.length === 0 && teamSavedFilters.length === 0 && (
        <p className="px-4 py-3 text-[13px] text-muted-foreground">
          No saved filters yet. Add filters in the Filters tab and save them
          here for quick access.
        </p>
      )}
      {filterMap.size > 0 && (
        <div className="border-t border-border px-4 py-2">
          <div className="space-y-1.5">
            <p className="text-[12px] text-muted-foreground">
              Save current filters with a name:
            </p>
            <div className="flex flex-nowrap items-center gap-2">
              {hasTeamLevel && (
                <div className="flex shrink-0 rounded-md border border-border overflow-hidden">
                  <button
                    type="button"
                    onClick={() => setSaveLevel('user')}
                    className={cn(
                      'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors',
                      saveLevel === 'user'
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground hover:bg-muted/60',
                    )}
                    aria-pressed={saveLevel === 'user'}
                  >
                    For me
                  </button>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        type="button"
                        onClick={() =>
                          canSaveTeamFiltersResult && setSaveLevel('team')
                        }
                        disabled={!canSaveTeamFiltersResult}
                        className={cn(
                          'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors border-l border-border',
                          saveLevel === 'team'
                            ? 'bg-muted text-foreground'
                            : 'text-muted-foreground hover:bg-muted/60 disabled:opacity-50',
                        )}
                        aria-pressed={saveLevel === 'team'}
                      >
                        For team
                      </button>
                    </TooltipTrigger>
                    {!canSaveTeamFiltersResult && (
                      <TooltipContent
                        side="top"
                        sideOffset={4}
                        className="z-[250]"
                      >
                        Only owners and developers can save team-level filters.
                      </TooltipContent>
                    )}
                  </Tooltip>
                </div>
              )}
              <Input
                placeholder="Filter name"
                value={saveName}
                onChange={(e) => setSaveName(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key !== 'Enter') return
                  e.preventDefault()
                  const name = saveName.trim()
                  if (!name || isAdding || savingId !== null) return
                  if (
                    hasTeamLevel &&
                    saveLevel === 'team' &&
                    !canSaveTeamFiltersResult
                  )
                    return
                  setSavingId('current')
                  addSavedFilter({
                    name,
                    query: mapToQueryParam(filterMap),
                    level: hasTeamLevel ? saveLevel : 'user',
                    ...(currentSortParam != null
                      ? { sort: currentSortParam }
                      : {}),
                  })
                    .then(() => setSaveName(''))
                    .finally(() => setSavingId(null))
                }}
                className="h-9 min-w-0 flex-1 text-[13px]"
                maxLength={64}
              />
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">
                    <Button
                      type="button"
                      size="icon"
                      variant="secondary"
                      className="h-9 w-9 shrink-0"
                      title="Save filter"
                      aria-label="Save filter"
                      disabled={
                        !saveName.trim() ||
                        isAdding ||
                        savingId !== null ||
                        (hasTeamLevel &&
                          saveLevel === 'team' &&
                          !canSaveTeamFiltersResult)
                      }
                      onClick={() => {
                        const name = saveName.trim()
                        if (!name) return
                        setSavingId('current')
                        addSavedFilter({
                          name,
                          query: mapToQueryParam(filterMap),
                          level: hasTeamLevel ? saveLevel : 'user',
                          ...(currentSortParam != null
                            ? { sort: currentSortParam }
                            : {}),
                        })
                          .then(() => setSaveName(''))
                          .finally(() => setSavingId(null))
                      }}
                    >
                      {isAdding && savingId === 'current' ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <Plus className="h-4 w-4" />
                      )}
                    </Button>
                  </span>
                </TooltipTrigger>
                {hasTeamLevel &&
                  saveLevel === 'team' &&
                  !canSaveTeamFiltersResult && (
                    <TooltipContent
                      side="top"
                      sideOffset={4}
                      className="z-[250]"
                    >
                      Only owners and developers can save team-level filters.
                    </TooltipContent>
                  )}
              </Tooltip>
            </div>
          </div>
        </div>
      )}
    </div>
  )

  return (
    <div className="flex max-h-[calc(100dvh-4rem)] flex-col pt-4">
      {hasSavedFiltersFeature ? (
        <Tabs
          defaultValue="filters"
          className="flex min-h-0 flex-col overflow-hidden"
        >
          <div className="shrink-0 px-4">
            <TabsList className="w-full grid grid-cols-2 h-9">
              <TabsTrigger value="filters" className="text-[13px]">
                Filters
                {filterMap.size > 0 && (
                  <span className="ml-1.5 flex size-4 items-center justify-center rounded-full bg-primary/20 text-[10px] font-medium tabular-nums text-primary">
                    {filterMap.size}
                  </span>
                )}
              </TabsTrigger>
              <TabsTrigger value="saved" className="text-[13px]">
                Saved
                {savedFilters.length > 0 && (
                  <span className="ml-1.5 flex size-4 items-center justify-center rounded-full bg-muted text-[10px] font-medium tabular-nums text-muted-foreground">
                    {savedFilters.length}
                  </span>
                )}
              </TabsTrigger>
            </TabsList>
          </div>
          <TabsContent
            value="filters"
            className="mt-0 max-h-[calc(100dvh-14rem)] min-h-0 overflow-y-auto"
          >
            {filtersTabContent}
          </TabsContent>
          <TabsContent
            value="saved"
            className="mt-0 max-h-[calc(100dvh-14rem)] min-h-0 overflow-y-auto"
          >
            {savedTabContent}
          </TabsContent>
        </Tabs>
      ) : (
        <div className="max-h-[calc(100dvh-14rem)] min-h-0 overflow-y-auto">
          {filtersTabContent}
        </div>
      )}
    </div>
  )
}
