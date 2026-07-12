/**
 * Shared filter popover content: active filters list + "Add condition" form.
 * Used inside FiltersPopover; can also be used with a custom Popover wrapper.
 */

import { useCallback, useEffect, useState } from 'react'
import { ArrowDown, ArrowUp, ChevronDown, Loader2, Plus, X } from 'lucide-react'
import {
  buildFilterQueryString,
  buildFilterTagFromCompactKey,
  bytesToSizeFilterInput,
  encodeSort,
  getOperatorsForType,
  getOperatorsForColumn,
  SIZE_FILTER_UNITS,
  sizeFilterToBytes,
} from '@/lib/table-filters'
import type {
  CompactFilterKey,
  FilterColumn,
  FilterColumnType,
  FilterMap,
} from '@/lib/table-filters'
import {
  compactFilterKeysEqual,
  findCompactFilterKeyInMap,
  mapToQueryParam,
} from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useSavedFilters } from '@/lib/react-query/hooks/auth'
import { useOrganizationScopes } from '@/lib/react-query/hooks'
import type { SavedFilter } from '@/lib/user-prefs-keys'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { ToolbarCountBadge } from '@/components/global/shared/ToolbarCountBadge'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { SavedFilterPresetRow } from '@/components/global/shared/SavedFilterPresetRow'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'

function reorderList<T>(list: T[], fromIndex: number, toIndex: number): T[] {
  const copy = [...list]
  const [removed] = copy.splice(fromIndex, 1)
  copy.splice(toIndex, 0, removed)
  return copy
}

/** Horizontal rules between popover sections - minimal */
const SECTION_DIVIDE =
  'border-muted-foreground/6 dark:border-muted-foreground/9'

/** Vertical split inside compact button groups (Asc | Desc, For me | For team) */
const SEGMENT_DIVIDE =
  'border-s border-muted-foreground/7 dark:border-muted-foreground/10'

const K1024 = 1024

function inferCustomAttributeType(key: CompactFilterKey): FilterColumnType {
  const { v, o } = key
  if (o === 'between' || o === 'notBetween') {
    const raw = v == null ? '' : Array.isArray(v) ? v.join(',') : String(v)
    const parts = raw
      .split(',')
      .map((p) => p.trim())
      .filter(Boolean)
    if (parts.length >= 2) {
      const n0 = Number(parts[0])
      const n1 = Number(parts[1])
      if (
        !Number.isNaN(n0) &&
        !Number.isNaN(n1) &&
        Number.isInteger(n0) &&
        Number.isInteger(n1)
      ) {
        return 'integer'
      }
      if (!Number.isNaN(n0) && !Number.isNaN(n1)) return 'double'
    }
  }
  if (typeof v === 'boolean') return 'boolean'
  if (typeof v === 'number') {
    return Number.isInteger(v) ? 'integer' : 'double'
  }
  if (typeof v === 'string') {
    if (/^\d{4}-\d{2}-\d{2}T/.test(v)) return 'datetime'
    return 'string'
  }
  return 'string'
}

export interface FiltersPopoverContentProps {
  columns: FilterColumn[]
  filterMap: FilterMap
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAll: () => void
  onApplyFilter: (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => void
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
  filterScope,
  onApplyQuery,
  teamId,
  sortBy,
  sortOrder,
  onSortChange,
  defaultSortParam,
  onReset,
}: FiltersPopoverContentProps) {
  const t = useT()
  const sortEnabled = sortBy != null && sortOrder != null && !!onSortChange
  const sortOptionsFromColumns = sortEnabled
    ? columns
        .filter((c) => !c.customAttributeSlot)
        .map((c) => ({ id: c.id, label: c.title }))
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

  const firstColumnId = columns[0]?.id ?? ''
  const firstCol = columns[0]
  const firstOperatorKey = firstColumnId
    ? (getOperatorsForColumn(firstCol!)[0]?.key ?? '')
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
  const [customDocumentAttrKey, setCustomDocumentAttrKey] = useState('')
  const [customDocumentAttrType, setCustomDocumentAttrType] =
    useState<FilterColumnType>('string')
  const [editingReplaceKey, setEditingReplaceKey] =
    useState<CompactFilterKey | null>(null)
  const [listSortOpen, setListSortOpen] = useState(false)

  const resetFormToDefaults = useCallback(() => {
    const first = columns[0]
    if (first) {
      setFilterColumnId(first.id)
      const ops = getOperatorsForColumn(first)
      setFilterOperatorKey(ops[0]?.key ?? '')
    }
    setFilterValue('')
    setFilterValueEnd('')
    setFilterSizeUnit('mb')
    setCustomDocumentAttrKey('')
    setCustomDocumentAttrType('string')
    setEditingReplaceKey(null)
  }, [columns])

  useEffect(() => {
    if (columns.length > 0 && !filterColumnId) {
      const first = columns[0]
      const ops = getOperatorsForColumn(first)
      setFilterColumnId(first.id)
      setFilterOperatorKey(ops[0]?.key ?? '')
    }
  }, [columns, filterColumnId])

  useEffect(() => {
    const column = columns.find((c) => c.id === filterColumnId)
    if (!column?.customAttributeSlot) return
    const ops = getOperatorsForType(customDocumentAttrType, {
      fulltextSearchable: false,
    })
    const stillValid = ops.some((o) => o.key === filterOperatorKey)
    if (!stillValid) {
      setFilterOperatorKey(ops[0]?.key ?? '')
    }
  }, [customDocumentAttrType, filterColumnId, filterOperatorKey, columns])

  const loadFilterFormFromCompactKey = (key: CompactFilterKey) => {
    let resolvedCol = columns.find((c) => c.id === key.c)
    let customKey = ''
    let customType: FilterColumnType = 'string'

    if (!resolvedCol) {
      const slot = columns.find((c) => c.customAttributeSlot)
      if (slot) {
        resolvedCol = slot
        customKey = key.c
        customType = inferCustomAttributeType(key)
      } else {
        resolvedCol = columns[0]
      }
    }

    if (!resolvedCol) return

    setFilterColumnId(resolvedCol.id)
    setFilterOperatorKey(key.o)
    setCustomDocumentAttrKey(customKey)
    setCustomDocumentAttrType(
      resolvedCol.customAttributeSlot ? customType : 'string',
    )

    const valueType = resolvedCol.customAttributeSlot
      ? customType
      : resolvedCol.type
    const operators = getOperatorsForType(valueType, {
      fulltextSearchable: resolvedCol.customAttributeSlot
        ? false
        : !!resolvedCol.fulltextSearchable,
      enumOptional: valueType === 'enum' ? resolvedCol.optional : undefined,
    })
    const opMeta = operators.find((o) => o.key === key.o)
    if (opMeta?.noValue) {
      setFilterValue('')
      setFilterValueEnd('')
      return
    }

    const isBetween = key.o === 'between' || key.o === 'notBetween'
    const rawV = key.v
    if (isBetween) {
      const raw =
        rawV == null ? '' : Array.isArray(rawV) ? rawV.join(',') : String(rawV)
      const parts = raw
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean)
      if (parts.length < 2) {
        setFilterValue('')
        setFilterValueEnd('')
        return
      }
      if (resolvedCol.format === 'size') {
        const numA = Number(parts[0])
        const numB = Number(parts[1])
        setFilterSizeUnit('mb')
        setFilterValue(
          !Number.isNaN(numA)
            ? String(numA / (K1024 * K1024))
            : (parts[0] ?? ''),
        )
        setFilterValueEnd(
          !Number.isNaN(numB)
            ? String(numB / (K1024 * K1024))
            : (parts[1] ?? ''),
        )
        return
      }
      if (valueType === 'datetime') {
        setFilterValue(parts[0] ?? '')
        setFilterValueEnd(parts[1] ?? '')
        return
      }
      setFilterValue(parts[0] ?? '')
      setFilterValueEnd(parts[1] ?? '')
      return
    }

    if (resolvedCol.format === 'size' && typeof rawV === 'number') {
      const { value, unit } = bytesToSizeFilterInput(rawV)
      setFilterValue(value)
      setFilterSizeUnit(unit)
      setFilterValueEnd('')
      return
    }

    if (resolvedCol.id === 'status' && typeof rawV === 'boolean') {
      setFilterValue(String(rawV))
      setFilterValueEnd('')
      return
    }

    if (valueType === 'boolean') {
      setFilterValue(
        typeof rawV === 'boolean' ? String(rawV) : String(rawV ?? ''),
      )
      setFilterValueEnd('')
      return
    }

    if (rawV === undefined || rawV === '') {
      setFilterValue('')
      setFilterValueEnd('')
      return
    }

    if (Array.isArray(rawV)) {
      setFilterValue(rawV.join(','))
      setFilterValueEnd('')
      return
    }

    setFilterValue(String(rawV))
    setFilterValueEnd('')
  }

  const beginEditFilter = (key: CompactFilterKey) => {
    setEditingReplaceKey(key)
    loadFilterFormFromCompactKey(key)
  }

  const cancelFilterEdit = () => {
    resetFormToDefaults()
  }

  useEffect(() => {
    if (!editingReplaceKey) return
    if (!findCompactFilterKeyInMap(filterMap, editingReplaceKey)) {
      resetFormToDefaults()
    }
  }, [filterMap, editingReplaceKey, resetFormToDefaults])

  useEffect(() => {
    const sortActive =
      sortBy != null && sortOrder != null && onSortChange != null
    if (!sortActive || defaultSortParam == null) return
    const currentSortParam = encodeSort(sortBy, sortOrder)
    if ((currentSortParam ?? defaultSortParam) !== defaultSortParam) {
      setListSortOpen(true)
    }
  }, [sortBy, sortOrder, onSortChange, defaultSortParam])

  const filterEntries = Array.from(filterMap.entries())

  const applyFilter = () => {
    const col = columns.find((c) => c.id === filterColumnId)
    if (!col || !filterOperatorKey) return

    const valueType = col.customAttributeSlot
      ? customDocumentAttrType
      : col.type
    const resolvedColumnId = col.customAttributeSlot
      ? customDocumentAttrKey.trim()
      : filterColumnId

    if (col.customAttributeSlot) {
      if (!resolvedColumnId || resolvedColumnId.startsWith('$')) return
    }

    const op = getOperatorsForType(valueType, {
      fulltextSearchable: col.customAttributeSlot
        ? false
        : !!col.fulltextSearchable,
      enumOptional: valueType === 'enum' ? col.optional : undefined,
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
      } else if (valueType === 'integer') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (valueType === 'bigint') {
        val = String(val)
      } else if (valueType === 'double') {
        const n = Number(val)
        val = Number.isNaN(n) ? String(val) : n
      } else if (valueType === 'boolean') {
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
      resolvedColumnId,
      val,
    )
    const compactKey: CompactFilterKey = {
      c: resolvedColumnId,
      o: filterOperatorKey,
      ...(val !== undefined && val !== '' ? { v: val } : {}),
    }
    const replaceKey =
      editingReplaceKey != null
        ? (findCompactFilterKeyInMap(filterMap, editingReplaceKey) ??
          editingReplaceKey)
        : undefined
    onApplyFilter(compactKey, queryString, replaceKey)
    setEditingReplaceKey(null)
    setFilterValue('')
    setFilterValueEnd('')
    // Keep popover open so users can add multiple filters in one go
  }

  const handleClearAll = () => {
    onClearAll()
    onClose?.()
  }

  const col = columns.find((c) => c.id === filterColumnId)
  const valueColumnType: FilterColumnType =
    col?.customAttributeSlot === true
      ? customDocumentAttrType
      : (col?.type ?? 'string')
  const op = col
    ? getOperatorsForColumn(col, valueColumnType).find(
        (operator) => operator.key === filterOperatorKey,
      )
    : null
  const needsValue = col && op && !op.noValue
  const isBetweenOp =
    filterOperatorKey === 'between' || filterOperatorKey === 'notBetween'
  const customDocAttrInvalid =
    col?.customAttributeSlot === true &&
    (!customDocumentAttrKey.trim() ||
      customDocumentAttrKey.trim().startsWith('$'))
  const isApplyDisabled =
    !filterColumnId ||
    !filterOperatorKey ||
    customDocAttrInvalid ||
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
    const vc: FilterColumn =
      col.customAttributeSlot === true
        ? {
            ...col,
            type: customDocumentAttrType,
            fulltextSearchable: false,
            elements: undefined,
            optional: undefined,
          }
        : col
    const label = <label className={labelClass}>{t('Value')}</label>
    if (isBetweenOp) {
      if (vc.format === 'size') {
        return (
          <div key="value-between-size" className="space-y-2">
            <div className="grid grid-cols-2 gap-2">
              <div>
                <span className={subLabelClass}>{t('Start')}</span>
                <Input
                  type="number"
                  min={0}
                  step={1}
                  className={inputClass}
                  value={filterValue}
                  onChange={(e) => setFilterValue(e.target.value)}
                  placeholder={t('Min')}
                />
              </div>
              <div>
                <span className={subLabelClass}>{t('End')}</span>
                <Input
                  type="number"
                  min={0}
                  step={1}
                  className={inputClass}
                  value={filterValueEnd}
                  onChange={(e) => setFilterValueEnd(e.target.value)}
                  placeholder={t('Max')}
                />
              </div>
            </div>
            <div>
              <span className={subLabelClass}>{t('Unit')}</span>
              <SearchableSelect
                value={filterSizeUnit}
                onValueChange={setFilterSizeUnit}
                items={SIZE_FILTER_UNITS.map((u) => ({
                  value: u.value,
                  label: t(u.label),
                }))}
                placeholder={t('Unit')}
                searchPlaceholder={t('Search units...')}
                emptyMessage={t('No units found')}
              />
            </div>
          </div>
        )
      }
      if (vc.type === 'datetime') {
        return (
          <div key="value-between-datetime" className="space-y-2">
            <div>
              <span className={subLabelClass}>{t('Start')}</span>
              <DateTimePicker
                value={filterValue || null}
                onChange={(v) => setFilterValue(v ?? '')}
                className={inputClass}
                clearable
              />
            </div>
            <div>
              <span className={subLabelClass}>{t('End')}</span>
              <DateTimePicker
                value={filterValueEnd || null}
                onChange={(v) => setFilterValueEnd(v ?? '')}
                className={inputClass}
                clearable
              />
            </div>
          </div>
        )
      }
      if (
        vc.type === 'integer' ||
        vc.type === 'bigint' ||
        vc.type === 'double'
      ) {
        return (
          <div key="value-between-number" className="space-y-2">
            <div>
              <span className={subLabelClass}>{t('Start')}</span>
              <Input
                type="number"
                step={vc.type === 'integer' || vc.type === 'bigint' ? 1 : 'any'}
                className={inputClass}
                value={filterValue}
                onChange={(e) => setFilterValue(e.target.value)}
                placeholder={t('Min')}
              />
            </div>
            <div>
              <span className={subLabelClass}>{t('End')}</span>
              <Input
                type="number"
                step={vc.type === 'integer' || vc.type === 'bigint' ? 1 : 'any'}
                className={inputClass}
                value={filterValueEnd}
                onChange={(e) => setFilterValueEnd(e.target.value)}
                placeholder={t('Max')}
              />
            </div>
          </div>
        )
      }
      return null
    }
    if (vc.type === 'boolean') {
      return (
        <div key="value-bool">
          {label}
          <SearchableSelect
            value={filterValue}
            onValueChange={setFilterValue}
            items={
              col.customAttributeSlot
                ? [
                    { value: 'true', label: t('True') },
                    { value: 'false', label: t('False') },
                  ]
                : [
                    { value: 'true', label: t('Enabled') },
                    { value: 'false', label: t('Disabled') },
                  ]
            }
            placeholder={t('Select')}
            searchPlaceholder={t('Search...')}
            emptyMessage={t('No results')}
          />
        </div>
      )
    }
    if (vc.type === 'datetime') {
      return (
        <div key="value-datetime">
          {label}
          <DateTimePicker
            value={filterValue || null}
            onChange={(v) => setFilterValue(v ?? '')}
            className={inputClass}
            clearable
          />
        </div>
      )
    }
    if (vc.format === 'size') {
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
              placeholder={t('Amount')}
            />
            <SearchableSelect
              value={filterSizeUnit}
              onValueChange={setFilterSizeUnit}
              items={SIZE_FILTER_UNITS.map((u) => ({
                value: u.value,
                label: t(u.label),
              }))}
              placeholder={t('Unit')}
              searchPlaceholder={t('Search units...')}
              emptyMessage={t('No units found')}
              triggerClassName="min-w-[100px]"
            />
          </div>
        </div>
      )
    }
    if (vc.type === 'integer' || vc.type === 'bigint') {
      return (
        <div key="value-int">
          {label}
          <Input
            type={vc.type === 'bigint' ? 'text' : 'number'}
            inputMode="numeric"
            step={vc.type === 'bigint' ? undefined : 1}
            className={inputClass}
            value={filterValue}
            onChange={(e) => setFilterValue(e.target.value)}
            placeholder={t('Number')}
          />
        </div>
      )
    }
    if (vc.type === 'enum' && vc.elements?.length) {
      return (
        <div key="value-enum">
          {label}
          <SearchableSelect
            value={filterValue}
            onValueChange={setFilterValue}
            items={vc.elements.map((el) => ({
              value: String(el.value),
              label: t(el.label),
              description: el.description ? t(el.description) : undefined,
              searchText: el.description
                ? `${t(el.label)} ${t(el.description)}`
                : undefined,
            }))}
            placeholder={t('Select')}
            searchPlaceholder={t('Search values...')}
            emptyMessage={t('No values found')}
          />
        </div>
      )
    }
    const searchOrNotSearch =
      filterOperatorKey === 'search' || filterOperatorKey === 'notSearch'
    const placeholder = searchOrNotSearch
      ? t('Min. 3 characters')
      : filterOperatorKey === 'regex'
        ? 'e.g. ^foo.*bar$'
        : t('Value')
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

  const sortFieldSummaryLabel =
    sortEnabled && sortOptionsFromColumns.length > 0
      ? (sortOptionsFromColumns.find((o) => o.id === sortBy)?.label ??
        sortBy ??
        '')
      : ''

  const filtersTabContent = (
    <>
      {sortEnabled && sortOptionsFromColumns.length > 0 && (
        <Collapsible open={listSortOpen} onOpenChange={setListSortOpen}>
          <div className={cn('border-t border-b', SECTION_DIVIDE)}>
            <CollapsibleTrigger asChild>
              <button
                type="button"
                className="flex w-full cursor-pointer items-center gap-2 px-4 py-2 text-start transition-colors hover:bg-muted/50"
              >
                <ChevronDown
                  className={cn(
                    'size-3.5 shrink-0 text-muted-foreground transition-transform duration-200',
                    listSortOpen && 'rotate-180',
                  )}
                  aria-hidden
                />
                <span className="min-w-0 flex-1 text-[12px] leading-snug">
                  <span className="font-medium text-foreground/85">
                    {t('List order')}
                  </span>
                  <span className="text-muted-foreground">
                    {' '}
                    · {t(sortFieldSummaryLabel)} ·{' '}
                    {sortOrder === 'asc' ? t('Ascending') : t('Descending')}
                  </span>
                </span>
              </button>
            </CollapsibleTrigger>
            <CollapsibleContent>
              <div
                className={cn(
                  'space-y-2 border-t bg-muted/20 px-4 py-2.5',
                  SECTION_DIVIDE,
                )}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                    {t('List order')}
                  </span>
                  {onReset && (hasNonDefaultSort || filterMap.size > 0) && (
                    <button
                      type="button"
                      onClick={() => {
                        onReset()
                        onClose?.()
                      }}
                      className="cursor-pointer shrink-0 text-[12px] font-medium text-muted-foreground hover:text-foreground transition-colors"
                    >
                      {t('Reset')}
                    </button>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <SearchableSelect
                    value={sortBy}
                    onValueChange={(field) => {
                      const nextOrder =
                        field === sortBy && sortOrder === 'desc'
                          ? 'asc'
                          : 'desc'
                      onSortChange!(field, nextOrder)
                    }}
                    items={sortOptionsFromColumns.map((o) => ({
                      value: o.id,
                      label: t(o.label),
                    }))}
                    placeholder={t('Sort field')}
                    searchPlaceholder={t('Search columns...')}
                    emptyMessage={t('No columns')}
                    triggerClassName="h-9 min-w-0 flex-1 text-[13px]"
                  />
                  <div
                    className={cn(
                      'flex shrink-0 overflow-hidden rounded-md border',
                      SECTION_DIVIDE,
                    )}
                  >
                    <button
                      type="button"
                      onClick={() => onSortChange!(sortBy!, 'asc')}
                      className={cn(
                        'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors',
                        sortOrder === 'asc'
                          ? 'bg-muted text-foreground'
                          : 'text-muted-foreground hover:bg-muted/60',
                      )}
                      aria-pressed={sortOrder === 'asc'}
                      title={t('Ascending')}
                    >
                      <ArrowUp className="h-3.5 w-3.5" />
                      {t('Asc')}
                    </button>
                    <button
                      type="button"
                      onClick={() => onSortChange!(sortBy!, 'desc')}
                      className={cn(
                        'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors',
                        SEGMENT_DIVIDE,
                        sortOrder === 'desc'
                          ? 'bg-muted text-foreground'
                          : 'text-muted-foreground hover:bg-muted/60',
                      )}
                      aria-pressed={sortOrder === 'desc'}
                      title={t('Descending')}
                    >
                      <ArrowDown className="h-3.5 w-3.5" />
                      {t('Desc')}
                    </button>
                  </div>
                </div>
              </div>
            </CollapsibleContent>
          </div>
        </Collapsible>
      )}

      <form
        onSubmit={(e) => {
          e.preventDefault()
          applyFilter()
        }}
        className={cn(
          'px-4 pb-3',
          sortEnabled && sortOptionsFromColumns.length > 0 ? 'pt-2' : 'pt-3',
        )}
      >
        <div className="space-y-2">
          <div className="grid grid-cols-2 gap-2">
            <div className="space-y-1">
              <label className={labelClass}>{t('Column')}</label>
              <SearchableSelect
                value={filterColumnId}
                onValueChange={(v) => {
                  setFilterColumnId(v)
                  setFilterValue('')
                  setFilterValueEnd('')
                  const column = columns.find((c) => c.id === v)
                  if (!column?.customAttributeSlot) {
                    setCustomDocumentAttrKey('')
                    setCustomDocumentAttrType('string')
                  } else {
                    setCustomDocumentAttrKey('')
                  }
                  if (column?.format === 'size') setFilterSizeUnit('mb')
                  const vt =
                    column?.customAttributeSlot === true
                      ? customDocumentAttrType
                      : (column?.type ?? 'string')
                  const firstOp = column ? getOperatorsForColumn(column, vt)[0] : null
                  setFilterOperatorKey(firstOp?.key ?? '')
                }}
                items={columns.map((c) => ({ value: c.id, label: t(c.title) }))}
                placeholder={t('Column')}
                searchPlaceholder={t('Search columns...')}
                emptyMessage={t('No columns found')}
              />
            </div>
            <div className="space-y-1">
              <label className={labelClass}>{t('Operator')}</label>
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
                        if (!column) return []
                        const vt =
                          column.customAttributeSlot === true
                            ? customDocumentAttrType
                            : column.type
                        return getOperatorsForColumn(column, vt).map((operator) => ({
                          value: operator.key,
                          label: t(operator.label),
                        }))
                      })()
                    : []
                }
                placeholder={t('Operator')}
                searchPlaceholder={t('Search operators...')}
                emptyMessage={t('No operators found')}
              />
            </div>
          </div>
          {col?.customAttributeSlot === true && (
            <div className="space-y-2">
              <div className="space-y-1">
                <label className={labelClass}>{t('Attribute name')}</label>
                <Input
                  className={inputClass}
                  value={customDocumentAttrKey}
                  onChange={(e) => setCustomDocumentAttrKey(e.target.value)}
                  placeholder={t('e.g. email, score, tags')}
                  autoComplete="off"
                />
                <p className="text-[11px] text-muted-foreground">
                  {t(
                    'Use preset columns for $id and other system fields. Custom names must not start with $.',
                  )}
                </p>
              </div>
              <div className="space-y-1">
                <label className={labelClass}>{t('Value type')}</label>
                <SearchableSelect
                  value={customDocumentAttrType}
                  onValueChange={(v) =>
                    setCustomDocumentAttrType(v as FilterColumnType)
                  }
                  items={[
                    { value: 'string', label: t('Text') },
                    { value: 'integer', label: t('Integer') },
                    { value: 'double', label: t('Decimal') },
                    { value: 'boolean', label: t('Boolean') },
                    { value: 'datetime', label: t('Date / time') },
                  ]}
                  placeholder={t('Type')}
                  searchPlaceholder={t('Search...')}
                  emptyMessage={t('No types')}
                  triggerClassName="h-9 w-full text-[13px]"
                />
              </div>
            </div>
          )}
          {filterColumnId && renderValueInput()}
          <div className="flex gap-2">
            <Button
              type="submit"
              size="sm"
              className="h-9 min-w-0 flex-1 text-[13px]"
              disabled={isApplyDisabled}
            >
              {editingReplaceKey ? t('Update filter') : t('Add filter')}
            </Button>
            {editingReplaceKey && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 shrink-0 text-[13px]"
                onClick={cancelFilterEdit}
              >
                {t('Cancel')}
              </Button>
            )}
          </div>
        </div>
      </form>

      {/* Active filters – below form so new filters appear here */}
      {filterMap.size > 0 && (
        <>
          <div className={cn('border-t', SECTION_DIVIDE)} />
          <div className="px-4 py-2">
            <div className="flex items-center justify-between gap-2 mb-1.5">
              <span className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider">
                {t('Active')} ({filterMap.size})
              </span>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 text-[12px] text-muted-foreground hover:text-foreground -me-1"
                onClick={handleClearAll}
              >
                {t('Clear all')}
              </Button>
            </div>
            <div className="max-h-40 overflow-y-auto space-y-1">
              {filterEntries.map(([key]) => {
                const tag = buildFilterTagFromCompactKey(key, columns)
                const parts = tag.tag.split(/\*\*/)
                const column = parts[1] ?? ''
                const operator = (parts[2] ?? '').trim()
                const value = parts[3] ?? null
                const isEditingThis =
                  editingReplaceKey != null &&
                  compactFilterKeysEqual(editingReplaceKey, key)
                return (
                  <div
                    key={`${key.c}-${key.o}-${JSON.stringify(key.v ?? '')}`}
                    className={cn(
                      'flex items-center gap-2 rounded-lg border bg-muted/30 px-2.5 py-1.5 group',
                      isEditingThis ? 'border-primary' : 'border-border',
                    )}
                  >
                    <button
                      type="button"
                      onClick={() => beginEditFilter(key)}
                      className="flex min-w-0 flex-1 cursor-pointer items-center gap-1.5 truncate text-start text-[12px] rounded-md outline-none hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring -mx-1 px-1 -my-0.5 py-0.5"
                    >
                      <span className="shrink-0 font-medium text-foreground">
                        {t(column)}
                      </span>
                      <span className="shrink-0 text-muted-foreground">
                        {t(operator)}
                      </span>
                      {value != null && value !== '' && (
                        <span className="truncate text-foreground">
                          {value}
                        </span>
                      )}
                    </button>
                    <button
                      type="button"
                      onClick={() => onRemoveFilter(key)}
                      className="cursor-pointer shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                      aria-label={t('Remove filter')}
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
        <div className={cn('border-t px-4 py-2', SECTION_DIVIDE)}>
          {matchingSavedFilter ? (
            <p className="text-[12px] text-muted-foreground">
              {t('Same as saved filter')} &quot;{matchingSavedFilter.name}
              &quot;
            </p>
          ) : (
            <div className="space-y-1.5">
              <p className="text-[12px] text-muted-foreground">
                {t('Save for later')}
              </p>
              <div className="flex flex-nowrap items-center gap-2">
                {hasTeamLevel && (
                  <div
                    className={cn(
                      'flex shrink-0 overflow-hidden rounded-md border',
                      SECTION_DIVIDE,
                    )}
                  >
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
                      {t('For me')}
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
                            'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors disabled:cursor-not-allowed',
                            SEGMENT_DIVIDE,
                            saveLevel === 'team'
                              ? 'bg-muted text-foreground'
                              : 'text-muted-foreground hover:bg-muted/60 disabled:opacity-50',
                          )}
                          aria-pressed={saveLevel === 'team'}
                        >
                          {t('For team')}
                        </button>
                      </TooltipTrigger>
                      {!canSaveTeamFiltersResult && (
                        <TooltipContent
                          side="top"
                          sideOffset={4}
                          className="z-[250]"
                        >
                          {t(
                            'Only owners and developers can save team-level filters.',
                          )}
                        </TooltipContent>
                      )}
                    </Tooltip>
                  </div>
                )}
                <Input
                  placeholder={t('Filter name')}
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
                        title={t('Save filter')}
                        aria-label={t('Save filter')}
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
                        {t(
                          'Only owners and developers can save team-level filters.',
                        )}
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

  const canEditTeamFilter = (l: 'user' | 'team') =>
    l === 'user' || canSaveTeamFiltersResult

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
                      {t('My filters')}
                    </p>
                    <div className="space-y-1">
                      {userSavedFilters.map((item, index) => (
                        <SavedFilterPresetRow
                          key={`user-${item.id}`}
                          item={item}
                          canEdit={canEditTeamFilter('user')}
                          dragOverKey={dragOverKey}
                          rowDropKey={rowDropKey('user', index)}
                          onDragStart={(e) =>
                            handleSavedFilterDragStart(e, 'user', index)
                          }
                          onDragOver={(e) => {
                            e.preventDefault()
                            e.dataTransfer.dropEffect = 'move'
                            setDragOverKey(rowDropKey('user', index))
                          }}
                          onDragLeave={() => setDragOverKey(null)}
                          onDrop={(e) =>
                            handleSavedFilterDrop(e, 'user', index)
                          }
                          onApply={() =>
                            onApplyQuery!(item.query || undefined, item.sort)
                          }
                          onDelete={() => {
                            setDeletingId(item.id)
                            void deleteSavedFilter(item.id, 'user').finally(
                              () => setDeletingId(null),
                            )
                          }}
                          deleteBusy={deletingId === item.id}
                          deleteDisabled={deletingId !== null}
                          onRenameCommit={(name) =>
                            updateSavedFilterName(item.id, 'user', name)
                          }
                        />
                      ))}
                    </div>
                  </div>
                )}
                {teamSavedFilters.length > 0 && (
                  <div>
                    <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider mb-1">
                      {t('Team filters')}
                    </p>
                    <div className="space-y-1">
                      {teamSavedFilters.map((item, index) => (
                        <SavedFilterPresetRow
                          key={`team-${item.id}`}
                          item={item}
                          canEdit={canEditTeamFilter('team')}
                          dragOverKey={dragOverKey}
                          rowDropKey={rowDropKey('team', index)}
                          onDragStart={(e) =>
                            handleSavedFilterDragStart(e, 'team', index)
                          }
                          onDragOver={(e) => {
                            e.preventDefault()
                            e.dataTransfer.dropEffect = 'move'
                            setDragOverKey(rowDropKey('team', index))
                          }}
                          onDragLeave={() => setDragOverKey(null)}
                          onDrop={(e) =>
                            handleSavedFilterDrop(e, 'team', index)
                          }
                          onApply={() =>
                            onApplyQuery!(item.query || undefined, item.sort)
                          }
                          onDelete={() => {
                            setDeletingId(item.id)
                            void deleteSavedFilter(item.id, 'team').finally(
                              () => setDeletingId(null),
                            )
                          }}
                          deleteBusy={deletingId === item.id}
                          deleteDisabled={deletingId !== null}
                          onRenameCommit={(name) =>
                            updateSavedFilterName(item.id, 'team', name)
                          }
                        />
                      ))}
                    </div>
                  </div>
                )}
              </>
            ) : (
              <div className="space-y-1">
                {savedFilters.map((item, index) => (
                  <SavedFilterPresetRow
                    key={`user-${item.id}`}
                    item={item}
                    canEdit={canEditTeamFilter('user')}
                    dragOverKey={dragOverKey}
                    rowDropKey={rowDropKey('user', index)}
                    onDragStart={(e) =>
                      handleSavedFilterDragStart(e, 'user', index)
                    }
                    onDragOver={(e) => {
                      e.preventDefault()
                      e.dataTransfer.dropEffect = 'move'
                      setDragOverKey(rowDropKey('user', index))
                    }}
                    onDragLeave={() => setDragOverKey(null)}
                    onDrop={(e) => handleSavedFilterDrop(e, 'user', index)}
                    onApply={() =>
                      onApplyQuery!(item.query || undefined, item.sort)
                    }
                    onDelete={() => {
                      setDeletingId(item.id)
                      void deleteSavedFilter(item.id, 'user').finally(() =>
                        setDeletingId(null),
                      )
                    }}
                    deleteBusy={deletingId === item.id}
                    deleteDisabled={deletingId !== null}
                    onRenameCommit={(name) =>
                      updateSavedFilterName(item.id, 'user', name)
                    }
                  />
                ))}
              </div>
            )}
          </div>
        </div>
      ) : null}
      {userSavedFilters.length === 0 && teamSavedFilters.length === 0 && (
        <p className="px-4 py-3 text-[13px] text-muted-foreground">
          {t(
            'No saved filters yet. Add filters in the Filters tab and save them here for quick access.',
          )}
        </p>
      )}
      {filterMap.size > 0 && (
        <div className={cn('border-t px-4 py-2', SECTION_DIVIDE)}>
          <div className="space-y-1.5">
            <p className="text-[12px] text-muted-foreground">
              {t('Save current filters with a name:')}
            </p>
            <div className="flex flex-nowrap items-center gap-2">
              {hasTeamLevel && (
                <div
                  className={cn(
                    'flex shrink-0 overflow-hidden rounded-md border',
                    SECTION_DIVIDE,
                  )}
                >
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
                    {t('For me')}
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
                          'flex h-9 cursor-pointer items-center gap-1 px-2 text-[12px] transition-colors disabled:cursor-not-allowed',
                          SEGMENT_DIVIDE,
                          saveLevel === 'team'
                            ? 'bg-muted text-foreground'
                            : 'text-muted-foreground hover:bg-muted/60 disabled:opacity-50',
                        )}
                        aria-pressed={saveLevel === 'team'}
                      >
                        {t('For team')}
                      </button>
                    </TooltipTrigger>
                    {!canSaveTeamFiltersResult && (
                      <TooltipContent
                        side="top"
                        sideOffset={4}
                        className="z-[250]"
                      >
                        {t(
                          'Only owners and developers can save team-level filters.',
                        )}
                      </TooltipContent>
                    )}
                  </Tooltip>
                </div>
              )}
              <Input
                placeholder={t('Filter name')}
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
                      title={t('Save filter')}
                      aria-label={t('Save filter')}
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
                      {t(
                        'Only owners and developers can save team-level filters.',
                      )}
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
                {t('Filters')}
                {filterMap.size > 0 ? (
                  <ToolbarCountBadge
                    count={filterMap.size}
                    placement="inline"
                  />
                ) : null}
              </TabsTrigger>
              <TabsTrigger value="saved" className="text-[13px]">
                {t('Saved')}
                {savedFilters.length > 0 ? (
                  <ToolbarCountBadge
                    count={savedFilters.length}
                    placement="inline"
                  />
                ) : null}
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
