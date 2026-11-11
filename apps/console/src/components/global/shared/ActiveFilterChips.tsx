import { useState, type FormEvent } from 'react'
import { ChevronDown, X } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  buildFilterQueryString,
  buildFilterTagFromCompactKey,
  compactFilterKeysEqual,
  findCompactFilterKeyInMap,
  getOperatorsForColumn,
  type CompactFilterKey,
  type FilterColumn,
  type FilterMap,
  type FilterOperatorDef,
} from '@/lib/table-filters'
import {
  resolveCountryDisplayName,
  type CountryLookups,
} from '@/lib/locale/country-lookups'

const RANGE_OPERATORS = new Set(['between', 'notBetween'])
const NUMBER_TYPES = new Set(['integer', 'double', 'bigint'])
// Values the chip cannot edit as typed text; the Filters popover still can.
const UNEDITABLE_TYPES = new Set(['datetime', 'point', 'linestring', 'polygon'])

/** No value, a range, or a single value: operators of one shape share a value. */
function operatorShape(operator: FilterOperatorDef): string {
  if (operator.noValue) return 'none'
  return RANGE_OPERATORS.has(operator.key) ? 'range' : 'single'
}

/** Operators a filter can switch to while keeping its column and value. */
function switchableOperators(
  column: FilterColumn | undefined,
  operator: FilterOperatorDef | undefined,
): FilterOperatorDef[] {
  if (!column || !operator) return []
  return getOperatorsForColumn(column).filter(
    (op) => operatorShape(op) === operatorShape(operator),
  )
}

/** Values to pick from (enums and booleans), or undefined to type one in. */
function valueOptions(
  column: FilterColumn,
): { value: string; label: string }[] | undefined {
  if (column.type === 'boolean') {
    return [
      { value: 'true', label: 'true' },
      { value: 'false', label: 'false' },
    ]
  }
  if (column.type === 'enum' && column.elements?.length) {
    return column.elements.map((element) => ({
      value: String(element.value),
      label: element.label,
    }))
  }
  return undefined
}

/** Whether a filter's value can be changed from its chip. */
function isValueEditable(
  key: CompactFilterKey,
  column: FilterColumn | undefined,
  operator: FilterOperatorDef | undefined,
): boolean {
  if (!column || !operator || operator.noValue) return false
  if (key.v === undefined || Array.isArray(key.v)) return false
  if (column.format === 'size' || column.customAttributeSlot) return false
  return !UNEDITABLE_TYPES.has(column.type)
}

/** The typed value the filter stores for what was entered or picked. */
function parseValue(
  column: FilterColumn,
  raw: string,
): string | number | boolean {
  if (column.type === 'boolean') return raw === 'true'
  if (column.type === 'integer' || column.type === 'double') {
    const number = Number(raw)
    return Number.isNaN(number) ? raw : number
  }
  if (column.type === 'enum') {
    const element = column.elements?.find((e) => String(e.value) === raw)
    if (element) return element.value
  }
  return raw
}

const SEGMENT_BUTTON =
  'flex cursor-pointer items-center border-s border-border px-2 hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring data-[state=open]:bg-muted data-[state=open]:text-foreground'

/**
 * One filter as a segmented chip: field, operator and value, then remove.
 * The operator and the value open in place to change them.
 */
function FilterChip({
  filterKey,
  tag,
  column,
  editable,
  onReplace,
  onRemove,
}: {
  filterKey: CompactFilterKey
  tag: string
  column: FilterColumn | undefined
  editable: boolean
  onReplace: (next: CompactFilterKey) => void
  onRemove: () => void
}) {
  const t = useT()
  const [editingValue, setEditingValue] = useState(false)
  const [draft, setDraft] = useState<string[]>([])

  // Tags read "**Field** operator **value**"; split them into segments.
  const [, field, operatorText = '', value] = tag.split('**')
  const operator = column
    ? getOperatorsForColumn(column).find((op) => op.key === filterKey.o)
    : undefined
  const operators = editable ? switchableOperators(column, operator) : []
  const operatorLabel = operator ? t(operator.label) : operatorText.trim()
  const valueEditable = editable && isValueEditable(filterKey, column, operator)
  const options = valueEditable && column ? valueOptions(column) : undefined
  const isRange = !!operator && RANGE_OPERATORS.has(operator.key)

  const openValueEditor = (open: boolean) => {
    if (open) {
      const raw = String(filterKey.v ?? '')
      setDraft(isRange ? raw.split(',').map((part) => part.trim()) : [raw])
    }
    setEditingValue(open)
  }

  const draftReady = draft.length > 0 && draft.every((part) => part.trim())

  const submitValue = (event: FormEvent) => {
    event.preventDefault()
    if (!column || !draftReady) return
    const raw = draft.map((part) => part.trim()).join(',')
    onReplace({ ...filterKey, v: isRange ? raw : parseValue(column, raw) })
    setEditingValue(false)
  }

  const inputType = column && NUMBER_TYPES.has(column.type) ? 'number' : 'text'

  return (
    <span className="inline-flex h-7 max-w-[28rem] items-stretch overflow-hidden rounded-md border border-border bg-card text-[12px]">
      <span className="flex min-w-0 items-center px-2 text-muted-foreground">
        <span className="truncate">{field}</span>
      </span>

      {operators.length > 1 ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className={`${SEGMENT_BUTTON} shrink-0 gap-1 text-foreground/80`}
              aria-label={t('Change operator')}
            >
              {operatorLabel}
              <ChevronDown className="h-3 w-3 text-muted-foreground" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start">
            <DropdownMenuRadioGroup
              value={filterKey.o}
              onValueChange={(o) => onReplace({ ...filterKey, o })}
            >
              {operators.map((op) => (
                <DropdownMenuRadioItem
                  key={op.key}
                  value={op.key}
                  className="text-[13px]"
                >
                  {t(op.label)}
                </DropdownMenuRadioItem>
              ))}
            </DropdownMenuRadioGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      ) : operatorLabel ? (
        <span className="flex shrink-0 items-center border-s border-border px-2 text-foreground/80">
          {operatorLabel}
        </span>
      ) : null}

      {value && options && column ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className={`${SEGMENT_BUTTON} min-w-0 font-medium text-foreground`}
              aria-label={t('Change value')}
            >
              <span className="truncate">{value}</span>
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent
            align="start"
            className="max-h-72 overflow-y-auto"
          >
            <DropdownMenuRadioGroup
              value={String(filterKey.v)}
              onValueChange={(raw) =>
                onReplace({ ...filterKey, v: parseValue(column, raw) })
              }
            >
              {options.map((option) => (
                <DropdownMenuRadioItem
                  key={option.value}
                  value={option.value}
                  className="text-[13px]"
                >
                  {t(option.label)}
                </DropdownMenuRadioItem>
              ))}
            </DropdownMenuRadioGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      ) : value && valueEditable ? (
        <Popover open={editingValue} onOpenChange={openValueEditor}>
          <PopoverTrigger asChild>
            <button
              type="button"
              className={`${SEGMENT_BUTTON} min-w-0 font-medium text-foreground`}
              aria-label={t('Change value')}
            >
              <span className="truncate">{value}</span>
            </button>
          </PopoverTrigger>
          <PopoverContent align="start" className="w-64 p-3">
            <form onSubmit={submitValue} className="space-y-3">
              <p className="text-[12px] text-muted-foreground">
                {field} {operatorLabel}
              </p>
              {draft.map((part, index) => (
                <Input
                  key={index}
                  type={inputType}
                  value={part}
                  autoFocus={index === 0}
                  onChange={(event) =>
                    setDraft((current) =>
                      current.map((entry, i) =>
                        i === index ? event.target.value : entry,
                      ),
                    )
                  }
                  aria-label={
                    isRange ? (index === 0 ? t('Start') : t('End')) : t('Value')
                  }
                  className="h-8 text-[13px]"
                />
              ))}
              <div className="flex justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() => setEditingValue(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="submit"
                  size="sm"
                  className="h-8 text-[12px]"
                  disabled={!draftReady}
                >
                  {t('Apply')}
                </Button>
              </div>
            </form>
          </PopoverContent>
        </Popover>
      ) : value ? (
        <span className="flex min-w-0 items-center border-s border-border px-2 font-medium text-foreground">
          <span className="truncate">{value}</span>
        </span>
      ) : null}

      <button
        type="button"
        onClick={onRemove}
        className="flex w-7 shrink-0 cursor-pointer items-center justify-center border-s border-border text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        aria-label={t('Remove filter')}
      >
        <X className="h-3 w-3" />
      </button>
    </span>
  )
}

/**
 * Removable chips for the active filters, shown under the toolbar so it's
 * always obvious the page is filtered. A chip's operator and value open in
 * place: the operators that keep the value, and the column's values or an
 * input to type a new one.
 */
export function ActiveFilterChips({
  filterMap,
  columns,
  countryLookups,
  onApplyFilter,
  onRemoveFilter,
  onClearAllFilters,
  fixedTag,
}: {
  filterMap: FilterMap
  columns: FilterColumn[]
  countryLookups?: CountryLookups | null
  onApplyFilter: (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => void
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAllFilters: () => void
  /** Tag for a filter that reads better in its own words; it cannot be edited. */
  fixedTag?: (key: CompactFilterKey) => string | undefined
}) {
  const t = useT()
  const keys = Array.from(filterMap.keys())

  if (keys.length === 0) return null

  const replace = (key: CompactFilterKey, next: CompactFilterKey) => {
    if (compactFilterKeysEqual(key, next)) return
    // Changing into a filter that is already applied just merges the two.
    if (findCompactFilterKeyInMap(filterMap, next)) {
      onRemoveFilter(key)
      return
    }
    onApplyFilter(next, buildFilterQueryString(next.o, next.c, next.v), key)
  }

  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2.5 sm:px-6">
      <span className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        {t('Filtered by')}
      </span>
      {keys.map((key) => {
        const fixed = fixedTag?.(key)
        // Show country names rather than ISO codes, like the Locations card.
        const displayKey =
          key.c === 'country' && typeof key.v === 'string' && countryLookups
            ? { ...key, v: resolveCountryDisplayName(key.v, countryLookups) }
            : key
        return (
          <FilterChip
            key={`${key.c}:${key.o}:${String(key.v)}`}
            filterKey={key}
            tag={fixed ?? buildFilterTagFromCompactKey(displayKey, columns).tag}
            column={columns.find((column) => column.id === key.c)}
            editable={!fixed}
            onReplace={(next) => replace(key, next)}
            onRemove={() => onRemoveFilter(key)}
          />
        )
      })}
      {keys.length > 1 ? (
        <Button
          variant="ghost"
          size="sm"
          className="h-7 px-2 text-[12px] text-muted-foreground"
          onClick={onClearAllFilters}
        >
          {t('Clear all')}
        </Button>
      ) : null}
    </div>
  )
}
