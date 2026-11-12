'use client'

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent,
  type ClipboardEvent,
} from 'react'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type TimeInputValue = {
  hour: number
  minute: number
  second: number
}

type TimeSegment = keyof TimeInputValue

const SEGMENT_ORDER: TimeSegment[] = ['hour', 'minute', 'second']
const SEGMENT_MAX: Record<TimeSegment, number> = {
  hour: 23,
  minute: 59,
  second: 59,
}

function pad2(value: number): string {
  return String(value).padStart(2, '0')
}

function clampSegment(value: number, max: number): number {
  return Math.min(max, Math.max(0, Math.trunc(value)))
}

function parsePastedTime(raw: string): TimeInputValue | null {
  const trimmed = raw.trim()
  const match = trimmed.match(/^(\d{1,2})[:.\s-]?(\d{1,2})(?:[:.\s-]?(\d{1,2}))?$/)
  if (!match) return null
  return {
    hour: clampSegment(parseInt(match[1], 10), 23),
    minute: clampSegment(parseInt(match[2], 10), 59),
    second: clampSegment(parseInt(match[3] ?? '0', 10), 59),
  }
}

export type TimeInputProps = {
  value: TimeInputValue
  onChange: (next: TimeInputValue) => void
  /** When set, arrow keys, wheel, and steppers delegate here instead of segment wrap. */
  onStep?: (unit: TimeSegment, delta: number, at: TimeInputValue) => void
  disabled?: boolean
  invalid?: boolean
  showSeconds?: boolean
  id?: string
  className?: string
}

function stepSegmentValue(
  value: TimeInputValue,
  segment: TimeSegment,
  delta: number,
): TimeInputValue {
  const max = SEGMENT_MAX[segment]
  let next = value[segment] + delta
  if (next > max) next = 0
  if (next < 0) next = max
  return { ...value, [segment]: next }
}

type SegmentFieldProps = {
  segment: TimeSegment
  value: number
  active: boolean
  disabled?: boolean
  invalid?: boolean
  inputRef: React.RefObject<HTMLInputElement | null>
  onFocus: () => void
  onCommit: (segment: TimeSegment, next: number) => void
  onAdvance: (segment: TimeSegment) => void
  onRetreat: (segment: TimeSegment) => void
  onStep: (
    segment: TimeSegment,
    delta: number,
    segmentOverride?: number,
  ) => void
}

function SegmentField({
  segment,
  value,
  active,
  disabled,
  invalid,
  inputRef,
  onFocus,
  onCommit,
  onAdvance,
  onRetreat,
  onStep,
}: SegmentFieldProps) {
  const t = useT()
  const [focused, setFocused] = useState(false)
  const [draft, setDraft] = useState('')
  const draftRef = useRef('')
  const labels: Record<TimeSegment, string> = {
    hour: t('Hours'),
    minute: t('Minutes'),
    second: t('Seconds'),
  }

  useEffect(() => {
    const padded = pad2(value)
    draftRef.current = padded
    setDraft(padded)
  }, [value])

  const display = focused ? draft : pad2(value)

  function normalizeDraft(raw: string): number {
    return clampSegment(parseInt(raw, 10) || 0, SEGMENT_MAX[segment])
  }

  function commitFromDraft(raw: string) {
    const next = normalizeDraft(raw)
    onCommit(segment, next)
    const padded = pad2(next)
    draftRef.current = padded
    setDraft(padded)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      event.stopPropagation()
      const override =
        focused && draftRef.current !== ''
          ? normalizeDraft(draftRef.current)
          : undefined
      onStep(segment, 1, override)
      return
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      event.stopPropagation()
      const override =
        focused && draftRef.current !== ''
          ? normalizeDraft(draftRef.current)
          : undefined
      onStep(segment, -1, override)
      return
    }
    if (event.key === 'ArrowRight' || event.key === ':') {
      event.preventDefault()
      if (focused && draftRef.current !== '') {
        commitFromDraft(draftRef.current)
      }
      onAdvance(segment)
      return
    }
    if (event.key === 'ArrowLeft') {
      event.preventDefault()
      onRetreat(segment)
      return
    }
    if (event.key === 'Backspace' && draft === '') {
      event.preventDefault()
      onRetreat(segment)
    }
  }

  return (
    <div
      className={cn(
        'relative flex items-center justify-center rounded-md px-1 py-1 transition-colors',
        active && 'bg-muted/70',
      )}
    >
      <input
        ref={inputRef}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        maxLength={2}
        role="spinbutton"
        aria-label={labels[segment]}
        aria-valuemin={0}
        aria-valuemax={SEGMENT_MAX[segment]}
        aria-valuenow={value}
        aria-invalid={invalid}
        disabled={disabled}
        value={display}
        onFocus={(event) => {
          const initial = pad2(value)
          draftRef.current = initial
          setFocused(true)
          setDraft(initial)
          onFocus()
          event.currentTarget.select()
        }}
        onBlur={() => {
          setFocused(false)
          commitFromDraft(draftRef.current)
        }}
        onChange={(event) => {
          const raw = event.target.value.replace(/\D/g, '').slice(0, 2)
          draftRef.current = raw
          setDraft(raw)
          if (raw === '') return
          const parsed = parseInt(raw, 10)
          if (Number.isNaN(parsed)) return
          const clamped = clampSegment(parsed, SEGMENT_MAX[segment])
          if (raw.length === 2 || clamped !== parsed) {
            commitFromDraft(String(clamped))
            if (raw.length === 2) onAdvance(segment)
          }
        }}
        onKeyDown={handleKeyDown}
        onWheel={(event) => {
          if (!focused) return
          event.preventDefault()
          const override =
            draftRef.current !== '' ? normalizeDraft(draftRef.current) : undefined
          onStep(segment, event.deltaY < 0 ? 1 : -1, override)
        }}
        className={cn(
          'w-[2ch] border-0 bg-transparent p-0 text-center text-[15px] font-medium tabular-nums outline-none',
          'selection:bg-primary/20 disabled:cursor-not-allowed',
        )}
      />
    </div>
  )
}

export function TimeInput({
  value,
  onChange,
  onStep,
  disabled,
  invalid,
  showSeconds = true,
  id,
  className,
}: TimeInputProps) {
  const t = useT()
  const hourRef = useRef<HTMLInputElement>(null)
  const minuteRef = useRef<HTMLInputElement>(null)
  const secondRef = useRef<HTMLInputElement>(null)
  const [activeSegment, setActiveSegment] = useState<TimeSegment>('hour')

  const visibleSegments = showSeconds
    ? SEGMENT_ORDER
    : (['hour', 'minute'] as TimeSegment[])

  const focusSegment = useCallback((segment: TimeSegment) => {
    setActiveSegment(segment)
    const ref =
      segment === 'hour'
        ? hourRef
        : segment === 'minute'
          ? minuteRef
          : secondRef
    ref.current?.focus()
    ref.current?.select()
  }, [])

  const refs: Record<TimeSegment, React.RefObject<HTMLInputElement | null>> = {
    hour: hourRef,
    minute: minuteRef,
    second: secondRef,
  }

  const advanceFrom = useCallback(
    (segment: TimeSegment) => {
      const index = visibleSegments.indexOf(segment)
      const next = visibleSegments[index + 1]
      if (next) focusSegment(next)
    },
    [focusSegment, visibleSegments],
  )

  const retreatFrom = useCallback(
    (segment: TimeSegment) => {
      const index = visibleSegments.indexOf(segment)
      const prev = visibleSegments[index - 1]
      if (prev) focusSegment(prev)
    },
    [focusSegment, visibleSegments],
  )

  const handleCommit = useCallback(
    (segment: TimeSegment, next: number) => {
      onChange({ ...value, [segment]: next })
    },
    [onChange, value],
  )

  const handleStep = useCallback(
    (
      segment: TimeSegment,
      delta: number,
      segmentOverride?: number,
    ) => {
      const at =
        segmentOverride !== undefined
          ? { ...value, [segment]: segmentOverride }
          : value
      if (onStep) {
        onStep(segment, delta, at)
        return
      }
      onChange(stepSegmentValue(at, segment, delta))
    },
    [onChange, onStep, value],
  )

  function handlePaste(event: ClipboardEvent<HTMLDivElement>) {
    if (disabled) return
    const parsed = parsePastedTime(event.clipboardData.getData('text'))
    if (!parsed) return
    event.preventDefault()
    onChange(
      showSeconds ? parsed : { ...parsed, second: value.second },
    )
    focusSegment(showSeconds ? 'second' : 'minute')
  }

  function handleContainerKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (disabled) return
    if (event.target instanceof HTMLInputElement) return
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      handleStep(activeSegment, 1)
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      handleStep(activeSegment, -1)
    }
  }

  return (
    <div
      id={id}
      dir="ltr"
      onPaste={handlePaste}
      onKeyDown={handleContainerKeyDown}
      className={cn(
        'flex h-10 w-fit max-w-full overflow-hidden rounded-lg border border-input bg-background shadow-xs transition-[color,box-shadow]',
        'focus-within:border-ring focus-within:ring-ring/50 focus-within:ring-[3px]',
        invalid &&
          'border-destructive focus-within:border-destructive focus-within:ring-destructive/20',
        disabled && 'pointer-events-none opacity-50',
        className,
      )}
    >
      <div className="flex items-center gap-0.5 pl-2.5 pr-1">
        {visibleSegments.map((segment, index) => (
          <div key={segment} className="flex items-center">
            {index > 0 ? (
              <span
                aria-hidden
                className="select-none px-1 text-[15px] font-medium text-muted-foreground/80"
              >
                :
              </span>
            ) : null}
            <SegmentField
              segment={segment}
              value={value[segment]}
              active={activeSegment === segment}
              disabled={disabled}
              invalid={invalid}
              inputRef={refs[segment]}
              onFocus={() => setActiveSegment(segment)}
              onCommit={handleCommit}
              onAdvance={advanceFrom}
              onRetreat={retreatFrom}
              onStep={handleStep}
            />
          </div>
        ))}
      </div>

      <div className="flex w-8 shrink-0 flex-col border-s border-border">
        <button
          type="button"
          tabIndex={-1}
          disabled={disabled}
          aria-label={t('Increase time')}
          onClick={() => handleStep(activeSegment, 1)}
          className="flex flex-1 items-center justify-center text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground disabled:pointer-events-none"
        >
          <ChevronUp className="size-3.5" />
        </button>
        <button
          type="button"
          tabIndex={-1}
          disabled={disabled}
          aria-label={t('Decrease time')}
          onClick={() => handleStep(activeSegment, -1)}
          className="flex flex-1 items-center justify-center border-t border-border text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground disabled:pointer-events-none"
        >
          <ChevronDown className="size-3.5" />
        </button>
      </div>
    </div>
  )
}
