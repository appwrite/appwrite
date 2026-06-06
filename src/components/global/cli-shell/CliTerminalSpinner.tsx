import { useEffect, useState } from 'react'
import { cn } from '@/lib/utils'

const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'] as const

const FRAME_INTERVAL_MS = 80

type CliTerminalSpinnerProps = {
  label?: string
  className?: string
}

/** Braille spinner styled like npm, yarn, and other terminal CLIs. */
export function CliTerminalSpinner({ label, className }: CliTerminalSpinnerProps) {
  const [frameIndex, setFrameIndex] = useState(0)

  useEffect(() => {
    const intervalId = window.setInterval(() => {
      setFrameIndex((current) => (current + 1) % SPINNER_FRAMES.length)
    }, FRAME_INTERVAL_MS)

    return () => window.clearInterval(intervalId)
  }, [])

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 font-mono text-[12px] leading-5 text-muted-foreground',
        className,
      )}
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <span className="inline-block w-[1ch] text-foreground/85 motion-reduce:hidden">
        {SPINNER_FRAMES[frameIndex]}
      </span>
      <span className="hidden w-[1ch] text-foreground/85 motion-reduce:inline">
        …
      </span>
      {label ? <span>{label}</span> : null}
      <span className="sr-only">{label ?? 'Loading'}</span>
    </span>
  )
}
