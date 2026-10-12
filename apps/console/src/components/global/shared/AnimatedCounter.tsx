import { useEffect, useRef, useState } from 'react'
import { cn } from '@/lib/utils'

type AnimatedCounterProps = {
  value: number
  className?: string
  durationMs?: number
  decimals?: number
  suffix?: string
  formatDisplay?: (value: number) => string
}

function easeOutCubic(progress: number): number {
  return 1 - (1 - progress) ** 3
}

function formatCounterValue(
  value: number,
  decimals?: number,
  formatDisplay?: (value: number) => string,
): string {
  if (formatDisplay) return formatDisplay(value)

  if (decimals !== undefined) {
    return value.toLocaleString(undefined, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    })
  }

  return Math.round(value).toLocaleString()
}

export function AnimatedCounter({
  value,
  className,
  durationMs = 500,
  decimals,
  suffix,
  formatDisplay,
}: AnimatedCounterProps) {
  const [displayValue, setDisplayValue] = useState(value)
  const displayValueRef = useRef(value)
  const frameRef = useRef<number | null>(null)
  const isFirstRenderRef = useRef(true)
  const useFractionalSteps = decimals !== undefined || !!formatDisplay

  useEffect(() => {
    displayValueRef.current = displayValue
  }, [displayValue])

  useEffect(() => {
    if (isFirstRenderRef.current) {
      isFirstRenderRef.current = false
      displayValueRef.current = value
      setDisplayValue(value)
      return
    }

    const from = displayValueRef.current
    if (value === from) return

    // Initial data load: snap instead of counting up from zero.
    if (from === 0 && value > 0) {
      displayValueRef.current = value
      setDisplayValue(value)
      return
    }

    const to = value
    const startTimeRef = { current: null as number | null }

    const animate = (timestamp: number) => {
      if (startTimeRef.current === null) {
        startTimeRef.current = timestamp
      }

      const elapsed = timestamp - startTimeRef.current
      const progress = Math.min(elapsed / durationMs, 1)
      const eased = easeOutCubic(progress)
      const next = useFractionalSteps
        ? from + (to - from) * eased
        : Math.round(from + (to - from) * eased)

      displayValueRef.current = next
      setDisplayValue(next)

      if (progress < 1) {
        frameRef.current = requestAnimationFrame(animate)
      } else {
        displayValueRef.current = to
        setDisplayValue(to)
      }
    }

    frameRef.current = requestAnimationFrame(animate)

    return () => {
      if (frameRef.current !== null) {
        cancelAnimationFrame(frameRef.current)
      }
    }
  }, [value, durationMs, useFractionalSteps])

  return (
    <span className={cn('tabular-nums', className)}>
      {formatCounterValue(displayValue, decimals, formatDisplay)}
      {suffix ?? ''}
    </span>
  )
}
