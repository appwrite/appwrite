'use client'

import {
  ArrowDownLeft,
  ArrowUpRight,
  Filter,
  Search,
  X,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Switch } from '@/components/ui/switch'
import { ToolbarCountBadge } from '@/components/global/shared/ToolbarCountBadge'
import {
  countActiveMessageFilters,
  createDefaultMessageLogFilters,
  MESSAGE_FRAME_TYPES,
  type MessageDirectionFilter,
  type MessageFrameType,
  type MessageLogFilters,
} from '@/lib/realtime/message-filters'
import {
  REALTIME_INCOMING_ICON_CLASS,
  REALTIME_OUTGOING_ICON_CLASS,
} from '@/lib/realtime/message-direction-styles'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type MessagesFilterBarProps = {
  filters: MessageLogFilters
  onChange: (filters: MessageLogFilters) => void
}

const DIRECTION_OPTIONS: {
  value: MessageDirectionFilter
  label: string
  icon?: typeof ArrowDownLeft
}[] = [
  { value: 'all', label: 'All' },
  { value: 'in', label: 'In', icon: ArrowDownLeft },
  { value: 'out', label: 'Out', icon: ArrowUpRight },
]

function FilterChip({
  label,
  onRemove,
}: {
  label: string
  onRemove: () => void
}) {
  const t = useT()
  return (
    <span className="inline-flex max-w-full items-center gap-1 rounded-md border border-border bg-muted/40 py-0.5 ps-2 pe-1 text-[11px] text-foreground">
      <span className="truncate">{label}</span>
      <button
        type="button"
        className="shrink-0 cursor-pointer rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        aria-label={`${t('Remove filter')}: ${label}`}
        onClick={onRemove}
      >
        <X className="h-3 w-3" />
      </button>
    </span>
  )
}

function directionIconClass(value: MessageDirectionFilter) {
  if (value === 'in') return REALTIME_INCOMING_ICON_CLASS
  if (value === 'out') return REALTIME_OUTGOING_ICON_CLASS
  return undefined
}

function directionSegmentClass(selected: boolean) {
  return cn(
    'h-7 gap-1 px-2.5 text-[12px] font-medium',
    selected
      ? 'bg-background text-foreground shadow-sm'
      : 'text-muted-foreground hover:bg-transparent hover:text-foreground',
  )
}

export function MessagesFilterBar({
  filters,
  onChange,
}: MessagesFilterBarProps) {
  const t = useT()
  const activeFilterCount = countActiveMessageFilters(filters)
  const hasActiveFilters = activeFilterCount > 0
  const partialTypes =
    filters.types.size > 0 && filters.types.size < MESSAGE_FRAME_TYPES.length

  const toggleFrameType = (type: MessageFrameType, checked: boolean) => {
    const nextTypes = new Set(filters.types)
    if (checked) {
      nextTypes.add(type)
    } else {
      nextTypes.delete(type)
    }
    onChange({ ...filters, types: nextTypes })
  }

  const handleClearFilters = () => {
    onChange(createDefaultMessageLogFilters())
  }

  const selectAllFrameTypes = () => {
    onChange({ ...filters, types: new Set(MESSAGE_FRAME_TYPES) })
  }

  const clearAllFrameTypes = () => {
    onChange({ ...filters, types: new Set() })
  }

  return (
    <div className="shrink-0 border-b border-border bg-muted/20">
      <div className="flex flex-wrap items-center gap-2 px-4 py-2.5">
        <div className="relative min-w-0 flex-1 basis-[160px]">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <input
            type="text"
            value={filters.search}
            onChange={(event) =>
              onChange({ ...filters, search: event.target.value })
            }
            placeholder={t('Search messages...') /* pragma: allowlist secret */}
            spellCheck={false}
            className="h-9 w-full min-w-0 rounded-md border border-border bg-background ps-8 pe-8 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
          />
          {filters.search ? (
            <button
              type="button"
              className="absolute end-2 top-1/2 -translate-y-1/2 cursor-pointer rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
              aria-label={t('Clear search')}
              onClick={() => onChange({ ...filters, search: '' })}
            >
              <X className="h-3.5 w-3.5" />
            </button>
          ) : null}
        </div>

        <div
          className="flex shrink-0 items-center rounded-md border border-border bg-muted/30 p-0.5"
          role="group"
          aria-label={t('Message direction')}
        >
          {DIRECTION_OPTIONS.map(({ value, label, icon: Icon }) => {
            const selected = filters.direction === value
            return (
              <Button
                key={value}
                type="button"
                variant="ghost"
                size="sm"
                className={directionSegmentClass(selected)}
                aria-pressed={selected}
                onClick={() => onChange({ ...filters, direction: value })}
              >
                {Icon ? (
                  <Icon
                    className={cn('h-3 w-3 shrink-0', directionIconClass(value))}
                  />
                ) : null}
                {t(label)}
              </Button>
            )
          })}
        </div>

        <Popover>
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className={cn(
                'h-9 shrink-0 gap-1.5 border-border bg-background text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground',
                hasActiveFilters && 'border-primary/30 text-foreground',
              )}
            >
              <Filter className="h-3.5 w-3.5 shrink-0" />
              {t('Filters')}
              {activeFilterCount > 0 ? (
                <ToolbarCountBadge
                  count={activeFilterCount}
                  placement="inline"
                  inlineTone="emphasis"
                />
              ) : null}
            </Button>
          </PopoverTrigger>
          <PopoverContent
            align="end"
            sideOffset={8}
            className="w-80 overflow-hidden rounded-xl border-border p-0 shadow-lg"
          >
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
              <p className="text-[13px] font-semibold text-foreground">
                {t('Message filters')}
              </p>
              {hasActiveFilters ? (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 px-2 text-[12px] text-muted-foreground hover:text-foreground"
                  onClick={handleClearFilters}
                >
                  {t('Clear all')}
                </Button>
              ) : null}
            </div>

            <div className="flex items-center justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                <Label
                  htmlFor="hide-ping-pong"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Hide ping/pong')}
                </Label>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {t('Hide heartbeat frames from the log')}
                </p>
              </div>
              <Switch
                id="hide-ping-pong"
                checked={filters.hidePingPong}
                onCheckedChange={(checked) =>
                  onChange({ ...filters, hidePingPong: checked })
                }
              />
            </div>

            <div className="border-t border-border px-4 py-3">
              <div className="mb-2.5 flex items-center justify-between gap-2">
                <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Frame types')}
                </p>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    className="text-[12px] font-medium text-primary hover:underline"
                    onClick={selectAllFrameTypes}
                  >
                    {t('All')}
                  </button>
                  <span className="text-muted-foreground/40">·</span>
                  <button
                    type="button"
                    className="text-[12px] font-medium text-muted-foreground hover:text-foreground hover:underline"
                    onClick={clearAllFrameTypes}
                  >
                    {t('None')}
                  </button>
                </div>
              </div>
              <div className="max-h-52 space-y-0.5 overflow-y-auto overscroll-contain pe-1">
                {MESSAGE_FRAME_TYPES.map((type) => (
                  <label
                    key={type}
                    className="flex cursor-pointer items-center gap-2.5 rounded-md px-1.5 py-1.5 transition-colors hover:bg-muted/50"
                  >
                    <Checkbox
                      checked={filters.types.has(type)}
                      onCheckedChange={(checked) =>
                        toggleFrameType(type, checked === true)
                      }
                    />
                    <span className="font-mono text-[12px] uppercase text-foreground">
                      {type}
                    </span>
                  </label>
                ))}
              </div>
            </div>
          </PopoverContent>
        </Popover>
      </div>

      {hasActiveFilters ? (
        <div className="flex flex-wrap items-center gap-1.5 border-t border-border/60 px-4 py-2">
          {filters.search.trim() ? (
            <FilterChip
              label={`${t('Search')}: ${filters.search.trim()}`}
              onRemove={() => onChange({ ...filters, search: '' })}
            />
          ) : null}
          {filters.direction !== 'all' ? (
            <FilterChip
              label={
                filters.direction === 'in' ? t('Incoming only') : t('Outgoing only')
              }
              onRemove={() => onChange({ ...filters, direction: 'all' })}
            />
          ) : null}
          {filters.hidePingPong ? (
            <FilterChip
              label={t('Hide ping/pong')}
              onRemove={() => onChange({ ...filters, hidePingPong: false })}
            />
          ) : null}
          {partialTypes ? (
            <FilterChip
              label={`${filters.types.size} ${t('frame types')}`}
              onRemove={selectAllFrameTypes}
            />
          ) : null}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-6 px-2 text-[11px] text-muted-foreground hover:text-foreground"
            onClick={handleClearFilters}
          >
            {t('Clear all')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
