import { useMemo, useState } from 'react'
import { ChevronDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useModalAwarePopover } from '@/lib/layout/modal-portal-host'
import {
  getUserTimeZone,
  listTimezoneOptions,
  timezoneMatchesQuery,
  type TimezoneOption,
} from '@/lib/timezones'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type TimezoneSelectProps = {
  value: string
  onValueChange: (timeZone: string) => void
  /** Instant used to compute DST-aware offsets in labels. */
  at?: Date
  disabled?: boolean
  id?: string
  placeholder?: string
  className?: string
}

function TimezoneOptionRow({ option }: { option: TimezoneOption }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5">
      <span className="truncate">{option.label}</span>
      {option.description ? (
        <span className="truncate text-[11px] text-muted-foreground">
          {option.description}
        </span>
      ) : null}
    </div>
  )
}

export function TimezoneSelect({
  value,
  onValueChange,
  at,
  disabled,
  id,
  placeholder = 'Select a timezone',
  className,
}: TimezoneSelectProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const { rootRef, portalContainer, modal, handleOpenChange } =
    useModalAwarePopover()
  const options = useMemo(() => listTimezoneOptions(at), [at])
  const selected = options.find((option) => option.id === value)
  const filtered = useMemo(
    () => options.filter((option) => timezoneMatchesQuery(option, query)),
    [options, query],
  )
  const popular = filtered.filter((option) => option.popular)
  const rest = filtered.filter((option) => !option.popular)
  const display = selected?.label || (value ? value : t(placeholder))

  function selectZone(next: string) {
    onValueChange(next)
    setOpen(false)
    setQuery('')
  }

  return (
    <div ref={rootRef} className="contents">
      <Popover
        open={open}
        modal={modal}
        onOpenChange={(nextOpen) => {
          handleOpenChange(nextOpen)
          setOpen(nextOpen)
          if (!nextOpen) setQuery('')
        }}
      >
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className={cn(
              'h-9 w-full justify-between gap-2 text-[13px] font-normal',
              className,
            )}
          >
            <span className="truncate">{display}</span>
            <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          container={portalContainer}
          align="start"
          className="w-[min(420px,calc(100vw-2rem))] overflow-hidden p-0"
          onWheelCapture={(event) => event.stopPropagation()}
          onCloseAutoFocus={(event) => {
            if (portalContainer) event.preventDefault()
          }}
        >
          <Command
            shouldFilter={false}
            filter={() => 1}
            className="overflow-hidden"
          >
            <CommandInput
              placeholder={t('Search timezones...')}
              value={query}
              onValueChange={setQuery}
              onKeyDown={(event) => event.stopPropagation()}
            />
            <CommandList className="max-h-[280px] overflow-y-auto overscroll-contain">
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
                          onSelect={() => selectZone(option.id)}
                        >
                          <TimezoneOptionRow option={option} />
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
                          onSelect={() => selectZone(option.id)}
                        >
                          <TimezoneOptionRow option={option} />
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  ) : null}
                </>
              )}
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}

export { getUserTimeZone }
