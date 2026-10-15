'use client'

import * as React from 'react'

import { Input } from '@/components/ui/input'
import { useT } from '@/lib/i18n/translate'
import {
  applySegmentDigit,
  extractInsertedText,
  normalizeTimeText,
  pad2,
  parsePastedTime,
  stepSegmentValue,
  TIME_SEGMENT_MAX,
  TIME_SEPARATOR_CHARS,
  type HourMinute,
} from '@/lib/time-segments'

type Segment = 'hour' | 'minute'

type Entry = { segment: Segment | null; buffer: string }

const SEGMENTS: readonly Segment[] = ['hour', 'minute']

function pairKey({ hour, minute }: HourMinute): string {
  return `${hour}:${minute}`
}

export type DateTimePickerTimeFieldsProps = {
  /** Wall-clock hour of the value in the picker zone; null shows placeholders */
  hour: number | null
  minute: number | null
  /** Zone of hour and minute, if shown; a change drops pairs typed in the old zone */
  timeZone?: string
  disabled?: boolean
  /**
   * Fires once per accepted keystroke with the full pair. Returns the wall
   * clock actually applied (after DST and min/max), which the fields show.
   */
  onChange: (next: HourMinute) => HourMinute
  /** Enter */
  onConfirm?: () => void
  /** Effective wall clock after DST resolution, so stepping skips gap times */
  resolve?: (next: HourMinute) => HourMinute
  'aria-labelledby'?: string
  'aria-describedby'?: string
}

/**
 * Segmented HH:mm entry. Inputs are uncontrolled: typed digits go through a
 * per-segment buffer and every accepted keystroke emits; focus and blur never
 * emit. While focused, a draft of the applied pair bridges the gap until the
 * parent re-renders. A parent that answers late and also rewrites the value is
 * resynced when focus leaves the group. Mobile keyboards and IMEs are handled
 * through native beforeinput, composition and input listeners.
 */
export function DateTimePickerTimeFields({
  hour,
  minute,
  timeZone,
  disabled,
  onChange,
  onConfirm,
  resolve,
  'aria-labelledby': labelledBy,
  'aria-describedby': describedBy,
}: DateTimePickerTimeFieldsProps) {
  const t = useT()
  const groupRef = React.useRef<HTMLDivElement>(null)
  const inputRefs = React.useRef<Record<Segment, HTMLInputElement | null>>({
    hour: null,
    minute: null,
  })
  const [entry, setEntry] = React.useState<Entry>({
    segment: null,
    buffer: '',
  })
  const entryRef = React.useRef<Entry>(entry)
  const [draft, setDraft] = React.useState<HourMinute | null>(null)
  const draftRef = React.useRef<HourMinute | null>(null)
  // Pairs applied since the draft started, and how many the props have echoed.
  const appliedRef = React.useRef<string[]>([])
  const echoedRef = React.useRef(0)
  // True from a commit until the next render, which carries a sync response.
  const committedRef = React.useRef(false)
  // Pair the typed segment started from, so a clamp or rounding of its first
  // digit does not leak into the other segment.
  const segmentBaseRef = React.useRef<HourMinute | null>(null)
  const timeZoneRef = React.useRef(timeZone)
  const propsRef = React.useRef({
    hour,
    minute,
    disabled,
    onChange,
    onConfirm,
    resolve,
  })
  const composingRef = React.useRef(false)
  const lastSyncedRef = React.useRef<Record<Segment, string>>({
    hour: '',
    minute: '',
  })
  const [, forceSync] = React.useReducer((n: number) => n + 1, 0)

  React.useLayoutEffect(() => {
    propsRef.current = { hour, minute, disabled, onChange, onConfirm, resolve }
  })

  // A value the parent changed on its own (rounded, replaced, other zone)
  // replaces the draft; echoes of pairs this draft applied do not. The entry
  // buffer stays so a half-typed segment continues.
  React.useLayoutEffect(() => {
    const zoneChanged = timeZoneRef.current !== timeZone
    timeZoneRef.current = timeZone
    if (!draftRef.current) return
    const applied = appliedRef.current
    if (!zoneChanged) {
      const key = hour === null ? null : pairKey({ hour, minute: minute ?? 0 })
      const echoed =
        key === null
          ? -1
          : applied.indexOf(key, Math.max(echoedRef.current - 1, 0))
      if (echoed >= 0) {
        echoedRef.current = echoed + 1
        return
      }
      if (key !== null && applied.includes(key)) return
      // Newer pairs are still unanswered, so this is a late, rewritten echo.
      if (!committedRef.current && echoedRef.current < applied.length) return
    }
    // Not a response to the last keystroke: continue from the new value.
    if (!committedRef.current) segmentBaseRef.current = null
    draftRef.current = null
    appliedRef.current = []
    echoedRef.current = 0
    setDraft(null)
  }, [hour, minute, timeZone])

  // Runs after the effect above. A commit onto the value the parent already
  // holds changes no props, so count it as echoed here.
  React.useLayoutEffect(() => {
    if (!committedRef.current) return
    committedRef.current = false
    const applied = appliedRef.current
    if (
      draftRef.current &&
      hour !== null &&
      echoedRef.current === applied.length - 1 &&
      applied[applied.length - 1] === pairKey({ hour, minute: minute ?? 0 })
    ) {
      echoedRef.current = applied.length
    }
  })

  const valuePair: HourMinute | null =
    draft ?? (hour === null ? null : { hour, minute: minute ?? 0 })

  function displayFor(segment: Segment): string {
    if (entry.segment === segment && entry.buffer !== '') return entry.buffer
    return valuePair ? pad2(valuePair[segment]) : ''
  }

  // Write display text imperatively; rewriting mid-composition breaks IMEs.
  React.useLayoutEffect(() => {
    if (composingRef.current) return
    for (const segment of SEGMENTS) {
      const el = inputRefs.current[segment]
      if (!el) continue
      const text = displayFor(segment)
      lastSyncedRef.current[segment] = text
      if (el.value !== text) {
        el.value = text
        if (el.ownerDocument.activeElement === el) {
          el.setSelectionRange(0, text.length)
        }
      }
    }
  })

  function writeEntry(next: Entry) {
    entryRef.current = next
    setEntry(next)
  }

  function current(): HourMinute | null {
    const props = propsRef.current
    return (
      draftRef.current ??
      (props.hour === null
        ? null
        : { hour: props.hour, minute: props.minute ?? 0 })
    )
  }

  function commit(next: HourMinute) {
    // Show what the picker applied (clamped, DST-shifted), not what was typed.
    const applied = propsRef.current.onChange(next)
    draftRef.current = applied
    appliedRef.current.push(pairKey(applied))
    committedRef.current = true
    setDraft(applied)
  }

  function focusSegment(segment: Segment) {
    writeEntry({ segment, buffer: '' })
    const el = inputRefs.current[segment]
    if (!el) return
    el.focus({ preventScroll: true })
    el.setSelectionRange(0, el.value.length)
  }

  function activeSegment(fallback: Segment): Segment {
    return entryRef.current.segment ?? fallback
  }

  /** Returns the segment that receives the next digit. */
  function typeDigit(segment: Segment, digit: number): Segment {
    const { segment: entrySegment, buffer } = entryRef.current
    const continuing = entrySegment === segment && buffer !== ''
    const result = applySegmentDigit(segment, continuing ? buffer : '', digit)
    const base = (continuing ? segmentBaseRef.current : null) ??
      current() ?? { hour: 0, minute: 0 }
    segmentBaseRef.current = base
    writeEntry({ segment, buffer: result.buffer })
    commit({ ...base, [segment]: result.value })
    if (result.complete && segment === 'hour') {
      focusSegment('minute')
      return 'minute'
    }
    return segment
  }

  function feedText(segment: Segment, text: string) {
    let target = segment
    for (const char of normalizeTimeText(text)) {
      if (char >= '0' && char <= '9') {
        target = typeDigit(target, Number(char))
      } else if (TIME_SEPARATOR_CHARS.has(char) && target === 'hour') {
        focusSegment('minute')
        target = 'minute'
      }
    }
    forceSync()
  }

  function backspace(segment: Segment, forward: boolean) {
    const cur = current()
    if (!cur) return
    const { segment: entrySegment, buffer } = entryRef.current
    if ((entrySegment === segment && buffer !== '') || cur[segment] !== 0) {
      writeEntry({ segment, buffer: '' })
      commit({ ...cur, [segment]: 0 })
      return
    }
    if (!forward && segment === 'minute') focusSegment('hour')
  }

  function step(segment: Segment, delta: 1 | -1) {
    writeEntry({ segment, buffer: '' })
    const cur = current()
    if (!cur) {
      commit({
        hour: 0,
        minute: 0,
        [segment]: delta > 0 ? 0 : TIME_SEGMENT_MAX[segment],
      })
      return
    }
    const resolveWallClock = propsRef.current.resolve
    for (let i = 1; i <= TIME_SEGMENT_MAX[segment] + 1; i++) {
      const value = stepSegmentValue(segment, cur[segment], delta * i)
      const candidate = { ...cur, [segment]: value }
      if (!resolveWallClock || resolveWallClock(candidate)[segment] === value) {
        commit(candidate)
        return
      }
    }
  }

  function setBoundary(segment: Segment, value: number) {
    writeEntry({ segment, buffer: '' })
    commit({ ...(current() ?? { hour: 0, minute: 0 }), [segment]: value })
  }

  const handlersRef = React.useRef({
    feedText,
    backspace,
    activeSegment,
    forceSync,
  })
  React.useLayoutEffect(() => {
    handlersRef.current = { feedText, backspace, activeSegment, forceSync }
  })

  function handleKeyDown(
    segment: Segment,
    event: React.KeyboardEvent<HTMLInputElement>,
  ) {
    if (propsRef.current.disabled) return
    // IME and virtual keyboards: handled by the native input listeners.
    if (
      event.nativeEvent.isComposing ||
      event.keyCode === 229 ||
      event.key === 'Process' ||
      event.key === 'Unidentified'
    ) {
      return
    }
    if (event.metaKey || event.ctrlKey || event.altKey) return
    const { key } = event
    if (key.length === 1 && key >= '0' && key <= '9') {
      event.preventDefault()
      event.stopPropagation()
      typeDigit(segment, Number(key))
      return
    }
    switch (key) {
      case 'Backspace':
      case 'Delete':
        event.preventDefault()
        event.stopPropagation()
        backspace(segment, key === 'Delete')
        return
      case 'ArrowUp':
      case 'ArrowDown':
        event.preventDefault()
        event.stopPropagation()
        step(segment, key === 'ArrowUp' ? 1 : -1)
        return
      case 'Home':
      case 'End':
        event.preventDefault()
        event.stopPropagation()
        setBoundary(segment, key === 'Home' ? 0 : TIME_SEGMENT_MAX[segment])
        return
      case 'ArrowLeft':
      case 'ArrowRight': {
        event.preventDefault()
        event.stopPropagation()
        // The group is dir="ltr", so physical and logical order match.
        const target: Segment = key === 'ArrowLeft' ? 'hour' : 'minute'
        if (target !== segment) focusSegment(target)
        else writeEntry({ segment, buffer: '' })
        return
      }
      case 'Enter':
        event.preventDefault()
        event.stopPropagation()
        writeEntry({ segment, buffer: '' })
        propsRef.current.onConfirm?.()
        return
      default:
        break
    }
    if (key.length === 1) {
      event.preventDefault()
      if (TIME_SEPARATOR_CHARS.has(key)) {
        event.stopPropagation()
        if (segment === 'hour') focusSegment('minute')
      }
    }
  }

  React.useEffect(() => {
    const cleanups: Array<() => void> = []
    for (const segment of SEGMENTS) {
      const el = inputRefs.current[segment]
      if (!el) continue
      const onBeforeInput = (event: InputEvent) => {
        if (event.isComposing || composingRef.current) return
        const type = event.inputType
        if (type.includes('Composition')) return
        const handlers = handlersRef.current
        if (type === 'insertText' || type === 'insertReplacementText') {
          if (!event.cancelable) return
          event.preventDefault()
          handlers.feedText(
            handlers.activeSegment(segment),
            event.data ?? event.dataTransfer?.getData('text/plain') ?? '',
          )
          return
        }
        if (type.startsWith('delete')) {
          if (!event.cancelable) return
          event.preventDefault()
          handlers.backspace(
            handlers.activeSegment(segment),
            type.endsWith('Forward'),
          )
          handlers.forceSync()
          return
        }
        // Paste is handled by the group's onPaste, which fires first.
        if (event.cancelable) event.preventDefault()
      }
      const onCompositionStart = () => {
        composingRef.current = true
      }
      const onCompositionEnd = (event: CompositionEvent) => {
        composingRef.current = false
        const handlers = handlersRef.current
        handlers.feedText(handlers.activeSegment(segment), event.data ?? '')
      }
      const onInput = (event: Event) => {
        const inputEvent = event as InputEvent
        if (composingRef.current || inputEvent.isComposing) return
        const handlers = handlersRef.current
        const type = inputEvent.inputType ?? ''
        // Firefox fires this after compositionend, which already fed the text.
        if (type.includes('Composition')) {
          handlers.forceSync()
          return
        }
        if (type.startsWith('delete')) {
          handlers.backspace(
            handlers.activeSegment(segment),
            type.endsWith('Forward'),
          )
        } else {
          handlers.feedText(
            handlers.activeSegment(segment),
            inputEvent.data ??
              extractInsertedText(lastSyncedRef.current[segment], el.value),
          )
        }
        handlers.forceSync()
      }
      el.addEventListener('beforeinput', onBeforeInput)
      el.addEventListener('compositionstart', onCompositionStart)
      el.addEventListener('compositionend', onCompositionEnd)
      el.addEventListener('input', onInput)
      cleanups.push(() => {
        el.removeEventListener('beforeinput', onBeforeInput)
        el.removeEventListener('compositionstart', onCompositionStart)
        el.removeEventListener('compositionend', onCompositionEnd)
        el.removeEventListener('input', onInput)
      })
    }
    return () => cleanups.forEach((cleanup) => cleanup())
  }, [])

  function handlePaste(event: React.ClipboardEvent<HTMLDivElement>) {
    event.preventDefault()
    const segment = entryRef.current.segment ?? 'hour'
    const parsed = parsePastedTime(
      event.clipboardData.getData('text/plain'),
      segment,
    )
    if (!parsed) return
    writeEntry({ segment, buffer: '' })
    commit({ ...(current() ?? { hour: 0, minute: 0 }), ...parsed })
  }

  function handleGroupBlur(event: React.FocusEvent<HTMLDivElement>) {
    if (groupRef.current?.contains(event.relatedTarget as Node | null)) return
    // Resync to the parent's value, which may differ if it transforms or rejects.
    draftRef.current = null
    appliedRef.current = []
    echoedRef.current = 0
    setDraft(null)
  }

  return (
    <div
      ref={groupRef}
      role="group"
      dir="ltr"
      aria-labelledby={labelledBy}
      aria-describedby={describedBy}
      className="flex items-center gap-1"
      onPaste={handlePaste}
      onBlur={handleGroupBlur}
    >
      {SEGMENTS.map((segment, index) => {
        const segmentValue = valuePair ? valuePair[segment] : null
        return (
          <React.Fragment key={segment}>
            {index > 0 && (
              <span aria-hidden className="text-[13px] text-muted-foreground">
                :
              </span>
            )}
            <Input
              ref={(el) => {
                inputRefs.current[segment] = el
              }}
              type="text"
              role="spinbutton"
              inputMode="numeric"
              enterKeyHint={segment === 'hour' ? 'next' : 'done'}
              autoComplete="off"
              autoCorrect="off"
              spellCheck={false}
              aria-label={t(segment === 'hour' ? 'Hours' : 'Minutes')}
              aria-valuemin={0}
              aria-valuemax={TIME_SEGMENT_MAX[segment]}
              aria-valuenow={segmentValue ?? undefined}
              aria-valuetext={
                segmentValue === null ? t('Not set') : pad2(segmentValue)
              }
              placeholder={segment === 'hour' ? 'HH' : 'mm'}
              disabled={disabled}
              onKeyDown={(event) => handleKeyDown(segment, event)}
              onFocus={(event) => {
                writeEntry({ segment, buffer: '' })
                event.currentTarget.setSelectionRange(
                  0,
                  event.currentTarget.value.length,
                )
              }}
              onBlur={() => {
                if (entryRef.current.segment === segment) {
                  writeEntry({ segment: null, buffer: '' })
                }
              }}
              // WebKit collapses the selection on mouseup.
              onMouseUp={(event) => event.preventDefault()}
              onPointerDown={(event) => event.stopPropagation()}
              className="h-7 w-11 px-1 text-center text-[12px] tabular-nums caret-transparent"
            />
          </React.Fragment>
        )
      })}
    </div>
  )
}
