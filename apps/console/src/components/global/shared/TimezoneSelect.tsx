import { useState } from 'react'
import { ChevronDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useModalAwarePopover } from '@/lib/layout/modal-portal-host'
import { getUserTimeZone, listTimezoneOptionsCached } from '@/lib/timezones'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { TimeZoneCommandList } from './TimeZoneCommandList'

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
  const { rootRef, portalContainer, modal, handleOpenChange } =
    useModalAwarePopover()
  const options = listTimezoneOptionsCached(at)
  const selected = options.find((option) => option.id === value)
  const display = selected?.label || (value ? value : t(placeholder))

  function selectZone(next: string) {
    onValueChange(next)
    setOpen(false)
  }

  return (
    <div ref={rootRef} className="contents">
      <Popover
        open={open}
        modal={modal}
        onOpenChange={(nextOpen) => {
          handleOpenChange(nextOpen)
          setOpen(nextOpen)
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
          <TimeZoneCommandList at={at} onSelect={selectZone} />
        </PopoverContent>
      </Popover>
    </div>
  )
}

export { getUserTimeZone }
