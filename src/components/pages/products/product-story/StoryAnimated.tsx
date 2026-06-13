import { useEffect, useState, type ReactNode } from 'react'
import { useStoryActive } from '@/components/pages/products/product-story/StoryActiveContext'
import { cn } from '@/lib/utils'

export function StoryAnimated({
  children,
  delayMs = 0,
  className,
}: {
  children: ReactNode
  delayMs?: number
  className?: string
}) {
  const active = useStoryActive()
  const [cycle, setCycle] = useState(0)

  useEffect(() => {
    if (active) setCycle((c) => c + 1)
  }, [active])

  return (
    <div
      key={cycle}
      className={cn('product-story-fade-up motion-reduce:opacity-100', className)}
      style={{ animationDelay: `${delayMs}ms` }}
    >
      {children}
    </div>
  )
}

export function StoryAnimatedProgress({
  target = 100,
  durationMs = 2200,
  className,
}: {
  target?: number
  durationMs?: number
  className?: string
}) {
  const active = useStoryActive()
  const [progress, setProgress] = useState(0)

  useEffect(() => {
    if (!active) {
      setProgress(0)
      return
    }

    const start = Date.now()
    const tick = () => {
      const elapsed = Date.now() - start
      const next = Math.min(target, (elapsed / durationMs) * target)
      setProgress(next)
      if (next < target) requestAnimationFrame(tick)
    }

    const frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
  }, [active, durationMs, target])

  return (
    <div className={cn('h-1.5 overflow-hidden rounded-full bg-muted/50', className)}>
      <div
        className="h-full rounded-full bg-[var(--brand-cta)]/80 transition-[width] duration-150 ease-out motion-reduce:transition-none"
        style={{ width: `${progress}%` }}
      />
    </div>
  )
}

export function StoryAnimatedLogs({
  lines,
  className,
}: {
  lines: {
    time: string
    message: string
    tone?: 'default' | 'success' | 'error'
    delayMs?: number
  }[]
  className?: string
}) {
  const active = useStoryActive()
  const [visibleCount, setVisibleCount] = useState(0)

  useEffect(() => {
    if (!active) {
      setVisibleCount(0)
      return
    }

    setVisibleCount(0)
    const timers = lines.map((line, index) =>
      window.setTimeout(
        () => setVisibleCount(index + 1),
        line.delayMs ?? index * 400,
      ),
    )

    return () => timers.forEach(clearTimeout)
  }, [active, lines])

  return (
    <div className={cn('space-y-0.5 rounded-lg border border-border bg-muted/10 p-3', className)}>
      {lines.map((line, index) => (
        <p
          key={`${line.time}-${line.message}`}
          className={cn(
            'font-mono text-[10px] leading-5 transition-[opacity,transform] duration-500 sm:text-[11px]',
            index < visibleCount ? 'translate-y-0 opacity-100' : 'translate-y-1 opacity-0',
            line.tone === 'success' && 'text-emerald-600 dark:text-emerald-400',
            line.tone === 'error' && 'text-destructive',
            (!line.tone || line.tone === 'default') && 'text-muted-foreground',
          )}
        >
          <span className="text-muted-foreground/60">{line.time}</span> {line.message}
        </p>
      ))}
    </div>
  )
}
