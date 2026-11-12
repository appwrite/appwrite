'use client'

import * as React from 'react'
import { ChevronLeft } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { formatTimeZoneLabel } from '@/lib/timezones'
import { TimeZoneCommandList } from './TimeZoneCommandList'

type DateTimePickerZonePanelProps = {
  storedTimeZone: string | null
  browserTimeZone: string
  at: Date
  onSelect: (timeZone: string | null) => void
  onBack: () => void
}

export function DateTimePickerZonePanel({
  storedTimeZone,
  browserTimeZone,
  at,
  onSelect,
  onBack,
}: DateTimePickerZonePanelProps) {
  const t = useT()
  const backRef = React.useRef<HTMLButtonElement>(null)
  const inputRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    // Focus Back on touch devices so the soft keyboard doesn't cover the list.
    const coarse = window.matchMedia('(pointer: coarse)').matches
    ;(coarse ? backRef.current : inputRef.current)?.focus({
      preventScroll: true,
    })
  }, [])

  return (
    <div
      className="absolute inset-0 z-10 flex flex-col bg-popover"
      onKeyDownCapture={(event) => {
        // Back and search are the only tabbables. Radix's Tab loop never sees keys
        // from inside cmdk and treats the inert calendar as its first edge.
        // Option+Tab is Safari's all-items traversal, so it loops here too.
        if (event.key !== 'Tab' || event.ctrlKey || event.metaKey) return
        event.preventDefault()
        event.stopPropagation()
        const next =
          document.activeElement === backRef.current
            ? inputRef.current
            : backRef.current
        next?.focus({ preventScroll: true })
      }}
    >
      <div className="flex h-10 shrink-0 items-center gap-1 border-b border-border px-2">
        <Button
          ref={backRef}
          type="button"
          variant="ghost"
          size="icon"
          className="size-7 cursor-pointer"
          aria-label={t('Back')}
          onClick={onBack}
        >
          <ChevronLeft className="size-4" />
        </Button>
        <span className="text-[12px] font-medium">{t('Timezone')}</span>
      </div>
      <TimeZoneCommandList
        at={at}
        onSelect={onSelect}
        selectedTimeZone={storedTimeZone}
        browserOption={{
          label: t('Browser timezone'),
          description: formatTimeZoneLabel(browserTimeZone, at),
          selected: storedTimeZone === null,
          onSelect: () => onSelect(null),
        }}
        inputRef={inputRef}
        label={t('Timezone')}
        className="min-h-0 flex-1"
        listClassName="max-h-none min-h-0 flex-1"
      />
    </div>
  )
}
