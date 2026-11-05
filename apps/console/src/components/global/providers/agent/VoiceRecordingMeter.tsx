import { useEffect, useRef } from 'react'
import { cn } from '@/lib/utils'
import { VOICE_LEVEL_BAR_COUNT } from '@/lib/assistant/voice-prompt'
import { useT } from '@/lib/i18n/translate'

/** Shared highlighter yellow for the submit phrase mark and countdown chrome. */
export const VOICE_SUBMIT_MARKER = {
  mark: 'box-decoration-clone rounded-[3px] bg-[#FFE566] px-1.5 py-0.5 text-amber-950 dark:bg-amber-400/45 dark:text-amber-50',
  solid: 'bg-[#FFE566] dark:bg-amber-400',
  soft: 'bg-[#FFE566]/70 dark:bg-amber-400/60',
  text: 'text-amber-900 dark:text-amber-200',
  bar: 'bg-[#E6C200] dark:bg-amber-400',
  row: 'bg-[#FFE566]/15 dark:bg-amber-400/10',
} as const

type VoiceRecordingMeterProps = {
  active: boolean
  /** Returns normalized 0–1 levels for each bar. Read from a ref so rAF stays cheap. */
  getLevels: () => number[]
  /** Whole seconds left on the voice submit countdown, if armed. */
  countdownSeconds?: number | null
  onCancelCountdown?: () => void
  className?: string
}

/**
 * Studio-style level meter driven by live microphone analyser data.
 * Updates bar heights via DOM (no React re-renders per frame).
 */
export function VoiceRecordingMeter({
  active,
  getLevels,
  countdownSeconds = null,
  onCancelCountdown,
  className,
}: VoiceRecordingMeterProps) {
  const t = useT()
  const barsRef = useRef<Array<HTMLSpanElement | null>>([])
  const getLevelsRef = useRef(getLevels)
  getLevelsRef.current = getLevels
  const countdownActive = countdownSeconds != null && countdownSeconds > 0

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
        'flex items-center gap-2.5 border-b border-border px-3 py-2 transition-colors',
        countdownActive && VOICE_SUBMIT_MARKER.row,
        className,
      )}
      role="status"
      aria-live="polite"
      aria-label={
        countdownActive
          ? `${t('Sending in')} ${countdownSeconds}`
          : t('Listening...')
      }
    >
      <span className="relative flex h-2 w-2 shrink-0">
        <span
          className={cn(
            'absolute inline-flex h-full w-full animate-ping rounded-full opacity-75',
            countdownActive ? VOICE_SUBMIT_MARKER.soft : 'bg-red-500/60',
          )}
        />
        <span
          className={cn(
            'relative inline-flex h-2 w-2 rounded-full',
            countdownActive ? VOICE_SUBMIT_MARKER.solid : 'bg-red-500',
          )}
        />
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
            className={cn(
              'w-[2.5px] origin-center rounded-full will-change-transform',
              countdownActive ? VOICE_SUBMIT_MARKER.bar : 'bg-primary',
            )}
            style={{
              height: '100%',
              transform: 'scaleY(0.12)',
              opacity: 0.35,
            }}
          />
        ))}
      </div>
      {countdownActive ? (
        <div className="flex shrink-0 items-center gap-2">
          <span
            className={cn(
              'text-[11px] font-medium tabular-nums',
              VOICE_SUBMIT_MARKER.text,
            )}
          >
            {t('Sending in')} {countdownSeconds}...
          </span>
          {onCancelCountdown ? (
            <button
              type="button"
              onClick={onCancelCountdown}
              className="rounded-md px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
              {t('Cancel')}
            </button>
          ) : null}
        </div>
      ) : (
        <span className="shrink-0 text-[11px] font-medium text-muted-foreground">
          {t('Listening...')}
        </span>
      )}
    </div>
  )
}
