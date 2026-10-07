import { MouseEvent, useEffect, useRef, useState } from 'react'
import { Check, Copy } from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { ColumnNumericDisplay } from '@/lib/utils/database-columns'

interface NumericValueTooltipProps {
  display: ColumnNumericDisplay
  className?: string
}

/**
 * Compact numeric label with a hover popover showing comma-grouped values and copy,
 * matching the interaction pattern of {@link DateTooltip}.
 */
export function NumericValueTooltip({
  display,
  className,
}: NumericValueTooltipProps) {
  const t = useT()
  const entries = display.entries?.length ? display.entries : null

  if (!entries?.length) {
    return (
      <code
        className={cn(
          'cursor-default font-mono text-[11px] text-foreground',
          className,
        )}
      >
        {display.label}
      </code>
    )
  }

  const [isOpen, setIsOpen] = useState(false)
  const [copiedIndex, setCopiedIndex] = useState<number | null>(null)
  const closeTimeoutRef = useRef<number | null>(null)

  useEffect(() => {
    return () => {
      if (closeTimeoutRef.current !== null) {
        window.clearTimeout(closeTimeoutRef.current)
      }
    }
  }, [])

  const openPopover = () => {
    if (closeTimeoutRef.current !== null) {
      window.clearTimeout(closeTimeoutRef.current)
      closeTimeoutRef.current = null
    }
    setIsOpen(true)
  }

  const scheduleClosePopover = () => {
    if (closeTimeoutRef.current !== null) {
      window.clearTimeout(closeTimeoutRef.current)
    }
    closeTimeoutRef.current = window.setTimeout(() => {
      setIsOpen(false)
      closeTimeoutRef.current = null
    }, 120)
  }

  const handleCopy = (
    index: number,
    value: string,
    event?: MouseEvent<HTMLElement>,
  ) => {
    event?.preventDefault()
    event?.stopPropagation()
    navigator.clipboard.writeText(value)
    setCopiedIndex(index)
    window.setTimeout(() => setCopiedIndex(null), 2000)
  }

  return (
    <Popover open={isOpen} onOpenChange={setIsOpen}>
      <PopoverTrigger asChild>
        <code
          className={cn(
            'cursor-default font-mono text-[11px] text-foreground',
            className,
          )}
          onMouseEnter={openPopover}
          onMouseLeave={scheduleClosePopover}
        >
          {display.label}
        </code>
      </PopoverTrigger>
      <PopoverContent
        side="top"
        align="center"
        sideOffset={8}
        className="w-auto max-w-[320px] p-0"
        onOpenAutoFocus={(event) => event.preventDefault()}
        onClick={(event) => event.stopPropagation()}
        onPointerDown={(event) => event.stopPropagation()}
        onMouseEnter={openPopover}
        onMouseLeave={scheduleClosePopover}
      >
        <div className="flex flex-col gap-1.5 px-3 py-2">
          {entries.map((entry, index) => (
            <button
              key={`${entry.label ?? 'value'}-${index}`}
              type="button"
              className="group flex w-full cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-start transition-colors hover:bg-muted/40"
              onClick={(event) =>
                handleCopy(index, entry.copyValue, event)
              }
            >
              {entry.label ? (
                <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                  {t(entry.label)}
                </span>
              ) : null}
              <span className="font-mono text-[13px] text-popover-foreground">
                {entry.detail}
              </span>
              <span
                className="ms-auto inline-flex h-6 w-6 shrink-0 items-center justify-center text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                aria-hidden="true"
              >
                {copiedIndex === index ? (
                  <Check className="h-3.5 w-3.5" />
                ) : (
                  <Copy className="h-3.5 w-3.5" />
                )}
              </span>
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  )
}
