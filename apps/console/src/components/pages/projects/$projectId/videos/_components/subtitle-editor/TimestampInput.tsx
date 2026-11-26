import {
  useRef,
  useState,
  type ClipboardEvent,
  type KeyboardEvent,
  type PointerEvent,
} from 'react'
import { Crosshair } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  formatEditorTimestamp,
  parseTimestampInput,
} from '@/lib/videos/subtitle-cues'

type SegmentKey = 'h' | 'm' | 's' | 'ms'
type Mode = 'timecode' | 'duration'

const SEGMENT_LABELS: Record<SegmentKey, string> = {
  h: 'Hours',
  m: 'Minutes',
  s: 'Seconds',
  ms: 'Milliseconds',
}

/** Seconds per pixel of label drag; Shift is 10x coarser, Alt 10x finer. */
const SCRUB_STEP = 0.01
const SCRUB_THRESHOLD = 3

function segmentsFor(value: number, mode: Mode): SegmentKey[] {
  if (mode === 'duration') return ['s', 'ms']
  return value >= 3600 ? ['h', 'm', 's', 'ms'] : ['m', 's', 'ms']
}

function partsOf(value: number, mode: Mode): Record<SegmentKey, number> {
  const totalMs = Math.max(0, Math.round(value * 1000))
  if (mode === 'duration') {
    return { h: 0, m: 0, s: Math.floor(totalMs / 1000), ms: totalMs % 1000 }
  }
  return {
    h: Math.floor(totalMs / 3_600_000),
    m: Math.floor((totalMs % 3_600_000) / 60_000),
    s: Math.floor((totalMs % 60_000) / 1000),
    ms: totalMs % 1000,
  }
}

function fromParts(parts: Record<SegmentKey, number>): number {
  return parts.h * 3600 + parts.m * 60 + parts.s + parts.ms / 1000
}

function maxDigits(key: SegmentKey, mode: Mode): number {
  return key === 'ms' || (key === 's' && mode === 'duration') ? 3 : 2
}

function segmentMax(key: SegmentKey, mode: Mode, hasHours: boolean): number {
  if (key === 'ms') return 999
  if (key === 's') return mode === 'duration' ? 999 : 59
  if (key === 'm') return hasHours ? 59 : 99
  return 99
}

function segmentText(
  key: SegmentKey,
  parts: Record<SegmentKey, number>,
  hasHours: boolean,
  mode: Mode,
): string {
  if (key === 'ms') return String(parts.ms).padStart(3, '0')
  if (key === 's') {
    return mode === 'duration' ? String(parts.s) : String(parts.s).padStart(2, '0')
  }
  if (key === 'm') return hasHours ? String(parts.m).padStart(2, '0') : String(parts.m)
  return String(parts.h)
}

const roundMs = (seconds: number) => Math.round(seconds * 1000) / 1000

/**
 * Segmented timecode field. Click a segment and type digits, use Up/Down to
 * nudge it (Shift for 10x), Left/Right to move between segments, or drag the
 * label sideways to scrub. Changes apply live; Escape restores the value the
 * field had when it was focused. Pasting any timestamp format replaces it.
 */
export function TimestampInput({
  id,
  label,
  value,
  onChange,
  onUsePlayhead,
  usePlayheadLabel,
  mode = 'timecode',
  min = 0,
  invalid = false,
  disabled = false,
}: {
  id: string
  label: string
  value: number
  onChange: (seconds: number) => void
  onUsePlayhead?: () => void
  usePlayheadLabel?: string
  mode?: Mode
  min?: number
  invalid?: boolean
  disabled?: boolean
}) {
  const t = useT()
  const labelId = `${id}-label`
  const segments = segmentsFor(value, mode)
  const hasHours = segments.includes('h')
  const parts = partsOf(value, mode)

  const [active, setActive] = useState<SegmentKey | null>(null)
  const segmentRefs = useRef<Partial<Record<SegmentKey, HTMLSpanElement | null>>>({})
  const bufferRef = useRef<{ key: SegmentKey; digits: string } | null>(null)
  const valueAtFocusRef = useRef<number | null>(null)
  const scrubRef = useRef<{ x: number; value: number; moved: boolean } | null>(null)
  const [scrubbing, setScrubbing] = useState(false)

  const apply = (next: number) => {
    const clamped = roundMs(Math.max(min, next))
    if (Math.abs(clamped - value) > 0.0005) onChange(clamped)
  }

  const focusSegment = (key: SegmentKey) => {
    bufferRef.current = null
    setActive(key)
    requestAnimationFrame(() => segmentRefs.current[key]?.focus())
  }

  const moveBy = (key: SegmentKey, offset: number) => {
    const order = segmentsFor(value, mode)
    const index = order.indexOf(key)
    const next = order[Math.min(order.length - 1, Math.max(0, index + offset))]
    if (next && next !== key) focusSegment(next)
  }

  const typeDigit = (key: SegmentKey, digit: string) => {
    const limit = maxDigits(key, mode)
    const previous = bufferRef.current?.key === key ? bufferRef.current.digits : ''
    const digits = previous.length >= limit ? digit : previous + digit
    bufferRef.current = { key, digits }

    const max = segmentMax(key, mode, hasHours)
    const raw = key === 'ms' ? Number(digits.padEnd(3, '0')) : Number(digits)
    apply(fromParts({ ...parts, [key]: Math.min(max, raw) }))

    const complete =
      digits.length >= limit || (key !== 'ms' && Number(digits) * 10 > max)
    if (complete) moveBy(key, 1)
  }

  const handleKeyDown = (key: SegmentKey, event: KeyboardEvent<HTMLSpanElement>) => {
    if (disabled) return
    const { key: pressed } = event
    if (/^\d$/.test(pressed)) {
      event.preventDefault()
      typeDigit(key, pressed)
      return
    }
    switch (pressed) {
      case 'ArrowUp':
      case 'ArrowDown': {
        event.preventDefault()
        bufferRef.current = null
        const unit = key === 'ms' ? 0.01 : key === 'h' ? 3600 : key === 'm' ? 60 : 1
        const step = unit * (event.shiftKey ? 10 : 1)
        apply(value + (pressed === 'ArrowUp' ? step : -step))
        return
      }
      case 'ArrowLeft':
        event.preventDefault()
        moveBy(key, -1)
        return
      case 'ArrowRight':
      case ':':
      case '.':
      case ',':
        event.preventDefault()
        moveBy(key, 1)
        return
      case 'Home':
        event.preventDefault()
        focusSegment(segments[0])
        return
      case 'End':
        event.preventDefault()
        focusSegment(segments[segments.length - 1])
        return
      case 'Backspace':
      case 'Delete':
        event.preventDefault()
        bufferRef.current = null
        apply(fromParts({ ...parts, [key]: 0 }))
        return
      case 'Enter':
        event.preventDefault()
        event.currentTarget.blur()
        return
      case 'Escape':
        event.preventDefault()
        if (valueAtFocusRef.current != null) apply(valueAtFocusRef.current)
        event.currentTarget.blur()
        return
    }
  }

  const handlePaste = (event: ClipboardEvent<HTMLDivElement>) => {
    if (disabled) return
    const parsed = parseTimestampInput(event.clipboardData.getData('text'))
    event.preventDefault()
    if (parsed != null && parsed >= 0) apply(parsed)
  }

  const handleScrubDown = (event: PointerEvent<HTMLSpanElement>) => {
    if (disabled || event.button !== 0) return
    event.preventDefault()
    event.currentTarget.setPointerCapture(event.pointerId)
    scrubRef.current = { x: event.clientX, value, moved: false }
  }

  const handleScrubMove = (event: PointerEvent<HTMLSpanElement>) => {
    const scrub = scrubRef.current
    if (!scrub) return
    const dx = event.clientX - scrub.x
    if (!scrub.moved) {
      if (Math.abs(dx) < SCRUB_THRESHOLD) return
      scrub.moved = true
      setScrubbing(true)
    }
    const step = event.shiftKey ? SCRUB_STEP * 10 : event.altKey ? SCRUB_STEP / 10 : SCRUB_STEP
    apply(scrub.value + dx * step)
  }

  const handleScrubEnd = (event: PointerEvent<HTMLSpanElement>) => {
    const scrub = scrubRef.current
    scrubRef.current = null
    setScrubbing(false)
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId)
    }
    if (scrub && !scrub.moved) focusSegment('s')
  }

  const focused = active != null
  const showError = invalid
  const tabSegment = active && segments.includes(active) ? active : 's'

  return (
    <div className="min-w-0 space-y-1">
      <span
        id={labelId}
        title={
          disabled
            ? undefined
            : t('Drag to adjust. Hold Shift for bigger steps or Alt for smaller ones.')
        }
        onPointerDown={handleScrubDown}
        onPointerMove={handleScrubMove}
        onPointerUp={handleScrubEnd}
        onPointerCancel={handleScrubEnd}
        className={cn(
          'block w-fit touch-none select-none text-[11px] font-medium text-muted-foreground',
          disabled ? 'cursor-default' : 'cursor-ew-resize hover:text-foreground',
          scrubbing && 'text-foreground',
        )}
      >
        {label}
      </span>
      <div className="flex items-center gap-1">
        <div
          id={id}
          role="group"
          aria-labelledby={labelId}
          aria-invalid={showError || undefined}
          dir="ltr"
          onPaste={handlePaste}
          onFocus={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
              valueAtFocusRef.current = value
            }
          }}
          onBlur={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
              setActive(null)
              bufferRef.current = null
              valueAtFocusRef.current = null
            }
          }}
          onPointerDown={(event) => {
            if (disabled || event.target !== event.currentTarget) return
            event.preventDefault()
            focusSegment(segments[segments.length - 1])
          }}
          className={cn(
            'flex h-8 min-w-0 flex-1 cursor-text items-center overflow-hidden rounded-md border border-input bg-transparent px-1.5 font-mono text-[12px] tabular-nums shadow-xs transition-[color,box-shadow] dark:bg-input/30',
            focused && 'border-ring ring-[3px] ring-ring/50',
            scrubbing && 'border-ring',
            showError && 'border-destructive',
            showError && focused && 'ring-destructive/20 dark:ring-destructive/40',
            disabled && 'pointer-events-none cursor-not-allowed opacity-50',
          )}
        >
          {segments.map((key, index) => (
            <span key={key} className="flex items-center">
              {index > 0 ? (
                <span aria-hidden className="text-muted-foreground">
                  {key === 'ms' ? '.' : ':'}
                </span>
              ) : null}
              <span
                ref={(el) => {
                  segmentRefs.current[key] = el
                }}
                role="spinbutton"
                tabIndex={disabled ? -1 : key === tabSegment ? 0 : -1}
                aria-label={t(SEGMENT_LABELS[key])}
                aria-valuenow={parts[key]}
                aria-valuemin={0}
                aria-valuemax={segmentMax(key, mode, hasHours)}
                aria-valuetext={formatEditorTimestamp(value)}
                onFocus={() => {
                  setActive(key)
                  bufferRef.current = null
                }}
                onKeyDown={(event) => handleKeyDown(key, event)}
                className={cn(
                  'rounded-[3px] px-px outline-none',
                  active === key
                    ? 'bg-primary text-primary-foreground'
                    : 'hover:bg-muted',
                )}
              >
                {segmentText(key, parts, hasHours, mode)}
              </span>
            </span>
          ))}
          {mode === 'duration' ? (
            <span aria-hidden className="ms-0.5 text-muted-foreground">
              s
            </span>
          ) : null}
        </div>
        {onUsePlayhead ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="h-8 w-8 shrink-0"
                disabled={disabled}
                aria-label={usePlayheadLabel ?? t('Use playhead')}
                onClick={onUsePlayhead}
              >
                <Crosshair className="h-3.5 w-3.5" />
              </Button>
            </TooltipTrigger>
            <TooltipContent className="text-[12px]">
              {usePlayheadLabel ?? t('Use playhead')}
            </TooltipContent>
          </Tooltip>
        ) : null}
      </div>
    </div>
  )
}
