import { useEffect, useRef } from 'react'
import { cn } from '@/lib/utils'
import { VOICE_LEVEL_BAR_COUNT } from '@/lib/assistant/voice-prompt'
import { useT } from '@/lib/i18n/translate'

type VoiceRecordingMeterProps = {
  active: boolean
  /** Returns normalized 0–1 levels for each bar. Read from a ref so rAF stays cheap. */
  getLevels: () => number[]
  className?: string
}

/**
 * Studio-style level meter driven by live microphone analyser data.
 * Updates bar heights via DOM (no React re-renders per frame).
 */
export function VoiceRecordingMeter({
  active,
  getLevels,
  className,
}: VoiceRecordingMeterProps) {
  const t = useT()
  const barsRef = useRef<Array<HTMLSpanElement | null>>([])
  const getLevelsRef = useRef(getLevels)
  getLevelsRef.current = getLevels

  useEffect(() => {
    if (!active) {
      for (const bar of barsRef.current) {
        if (!bar) continue
        bar.style.transform = 'scaleY(0.12)'
        bar.style.opacity = '0.35'
      }
      return
    }

    let rafId = 0
    let idlePhase = 0

    const tick = () => {
      const levels = getLevelsRef.current()
      let energy = 0
      for (let i = 0; i < VOICE_LEVEL_BAR_COUNT; i += 1) {
        energy += levels[i] ?? 0
      }
      energy /= VOICE_LEVEL_BAR_COUNT
      idlePhase += 0.08

      for (let i = 0; i < VOICE_LEVEL_BAR_COUNT; i += 1) {
        const bar = barsRef.current[i]
        if (!bar) continue
        const level = levels[i] ?? 0
        // Soft idle ripple when the room is quiet so the UI still feels alive.
        const idle =
          energy < 0.04
            ? 0.08 +
              Math.sin(idlePhase + i * 0.45) * 0.05 +
              Math.sin(idlePhase * 0.6 + i * 0.2) * 0.03
            : 0
        const value = Math.min(1, Math.max(level, idle))
        bar.style.transform = `scaleY(${0.12 + value * 0.88})`
        bar.style.opacity = String(0.4 + value * 0.6)
      }

      rafId = window.requestAnimationFrame(tick)
    }

    rafId = window.requestAnimationFrame(tick)
    return () => window.cancelAnimationFrame(rafId)
  }, [active])

  return (
    <div
      className={cn(
        'flex items-center gap-2.5 border-b border-border px-3 py-2',
        className,
      )}
      role="status"
      aria-live="polite"
      aria-label={t('Listening...')}
    >
      <span className="relative flex h-2 w-2 shrink-0">
        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500/60 opacity-75" />
        <span className="relative inline-flex h-2 w-2 rounded-full bg-red-500" />
      </span>
      <div
        className="flex h-7 flex-1 items-center justify-center gap-[2px]"
        aria-hidden
      >
        {Array.from({ length: VOICE_LEVEL_BAR_COUNT }, (_, index) => (
          <span
            key={index}
            ref={(node) => {
              barsRef.current[index] = node
            }}
            className="w-[2.5px] origin-center rounded-full bg-primary will-change-transform"
            style={{
              height: '100%',
              transform: 'scaleY(0.12)',
              opacity: 0.35,
            }}
          />
        ))}
      </div>
      <span className="shrink-0 text-[11px] font-medium text-muted-foreground">
        {t('Listening...')}
      </span>
    </div>
  )
}
