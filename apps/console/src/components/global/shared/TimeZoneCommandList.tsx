import * as React from 'react'
import { Check } from 'lucide-react'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  listTimezoneOptionsCached,
  timezoneMatchesQuery,
} from '@/lib/timezones'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type TimeZoneCommandListProps = {
  /** Instant used to compute DST-aware offsets in labels. */
  at?: Date
  onSelect: (timeZone: string) => void
  /** Shows a check on that row. */
  selectedTimeZone?: string | null
  /** Extra first row, shown only while the search is empty. */
  browserOption?: {
    label: string
    description: string
    selected: boolean
    onSelect: () => void
  }
  inputRef?: React.Ref<HTMLInputElement>
  /** Accessible name for the search input and list */
  label?: string
  /** Classes for the Command root */
  className?: string
  listClassName?: string
}

function TimezoneOptionRow({
  label,
  description,
  selected,
}: {
  label: React.ReactNode
  description?: React.ReactNode
  selected: boolean
}) {
  return (
    <>
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="truncate">{label}</span>
        {description ? (
          <span className="truncate text-[11px] text-muted-foreground">
            {description}
          </span>
        ) : null}
      </div>
      {selected ? <Check className="size-3.5 shrink-0" /> : null}
    </>
  )
}

export function TimeZoneCommandList({
  at,
  onSelect,
  selectedTimeZone,
  browserOption,
  inputRef,
  label,
  className,
  listClassName,
}: TimeZoneCommandListProps) {
  const t = useT()
  const [query, setQuery] = React.useState('')
  const options = listTimezoneOptionsCached(at)
  const filtered = React.useMemo(
    () => options.filter((option) => timezoneMatchesQuery(option, query)),
    [options, query],
  )
  const popular = filtered.filter((option) => option.popular)
  const rest = filtered.filter((option) => !option.popular)

  return (
    <Command
      shouldFilter={false}
      filter={() => 1}
      label={label}
      className={cn('overflow-hidden', className)}
      // On the root, not the input: cmdk's Arrow/Enter handling lives here.
      onKeyDown={(event) => event.stopPropagation()}
    >
      <CommandInput
        ref={inputRef}
        placeholder={t('Search timezones...')}
        value={query}
        onValueChange={setQuery}
      />
      <CommandList
        className={cn(
          'max-h-[280px] overflow-y-auto overscroll-contain',
          listClassName,
        )}
      >
        {browserOption && !query ? (
          <CommandGroup>
            <CommandItem
              value="browser"
              className="text-[13px]"
              onSelect={browserOption.onSelect}
            >
              <TimezoneOptionRow
                label={browserOption.label}
                description={<bdi dir="ltr">{browserOption.description}</bdi>}
                selected={browserOption.selected}
              />
            </CommandItem>
          </CommandGroup>
        ) : null}
        {filtered.length === 0 ? (
          <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
            {t('No timezones found')}
          </CommandEmpty>
        ) : (
          <>
            {popular.length > 0 ? (
              <CommandGroup heading={t('Popular')}>
                {popular.map((option) => (
                  <CommandItem
                    key={`popular-${option.id}`}
                    value={`popular:${option.id}`}
                    className="text-[13px]"
                    onSelect={() => onSelect(option.id)}
                  >
                    <TimezoneOptionRow
                      label={<bdi dir="ltr">{option.label}</bdi>}
                      description={option.description}
                      selected={selectedTimeZone === option.id}
                    />
                  </CommandItem>
                ))}
              </CommandGroup>
            ) : null}
            {rest.length > 0 ? (
              <CommandGroup heading={t('All timezones')}>
                {rest.map((option) => (
                  <CommandItem
                    key={`all-${option.id}`}
                    value={`all:${option.id}`}
                    className="text-[13px]"
                    onSelect={() => onSelect(option.id)}
                  >
                    <TimezoneOptionRow
                      label={<bdi dir="ltr">{option.label}</bdi>}
                      description={option.description}
                      selected={selectedTimeZone === option.id}
                    />
                  </CommandItem>
                ))}
              </CommandGroup>
            ) : null}
          </>
        )}
      </CommandList>
    </Command>
  )
}
