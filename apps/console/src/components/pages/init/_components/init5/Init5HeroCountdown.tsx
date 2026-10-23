import { AnimatePresence, motion } from 'motion/react'
import { Fragment, useEffect, useMemo, useState } from 'react'
import { resolveInitDayUnlockDate } from '@/lib/init/dates'
import { useT } from '@/lib/i18n/translate'

const DIGIT_EASE: [number, number, number, number] = [0.16, 1, 0.3, 1]

function pad(value: number) {
  return String(value).padStart(2, '0')
}

/** Each digit rolls in from above when it changes (departure-board feel). */
function RollingDigits({
  value,
  reduceMotion,
}: {
  value: string
  reduceMotion: boolean
}) {
  return (
    <span className="inline-flex overflow-hidden py-0.5">
      {value.split('').map((digit, index) => (
        <span
          key={`${value.length}-${index}`}
          className="relative inline-block w-[0.62em] text-center"
        >
          <AnimatePresence mode="popLayout" initial={false}>
            <motion.span
              key={digit}
              className="inline-block"
              initial={
                reduceMotion
                  ? false
                  : { y: '-75%', opacity: 0, filter: 'blur(3px)' }
              }
              animate={{ y: '0%', opacity: 1, filter: 'blur(0px)' }}
              exit={
                reduceMotion
                  ? undefined
                  : { y: '75%', opacity: 0, filter: 'blur(3px)' }
              }
              transition={{ duration: 0.5, ease: DIGIT_EASE }}
            >
              {digit}
            </motion.span>
          </AnimatePresence>
        </span>
      ))}
    </span>
  )
}

/** Live "Starts in" ticker to day 1 (same unlock moment as the day cards). */
export function Init5HeroCountdown({
  startDate,
  reduceMotion,
}: {
  startDate: string
  reduceMotion: boolean
}) {
  const t = useT()
  const target = useMemo(
    () => resolveInitDayUnlockDate(startDate, 1).getTime(),
    [startDate],
  )
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const interval = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(interval)
  }, [])

  const remaining = target - now
  if (remaining <= 0) return null

  const total = Math.floor(remaining / 1000)
  const days = Math.floor(total / 86_400)
  const hours = Math.floor((total % 86_400) / 3_600)
  const minutes = Math.floor((total % 3_600) / 60)
  const seconds = total % 60

  const segments = [
    { id: 'days', value: pad(days), label: t('Days') },
    { id: 'hours', value: pad(hours), label: t('Hours') },
    { id: 'minutes', value: pad(minutes), label: t('Minutes') },
    { id: 'seconds', value: pad(seconds), label: t('Seconds') },
  ]

  return (
    <div
      role="timer"
      aria-label={`${t('Starts in')} ${days}d ${hours}h ${minutes}m`}
      className="flex flex-col items-center gap-3 sm:flex-row sm:gap-5 lg:items-center"
    >
      <span className="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.26em] text-muted-foreground">
        <span className="relative flex size-1.5" aria-hidden>
          {reduceMotion ? null : (
            <span className="absolute inline-flex size-full animate-ping rounded-full bg-[var(--brand-cta)] opacity-60" />
          )}
          <span className="relative inline-flex size-1.5 rounded-full bg-[var(--brand-cta)]" />
        </span>
        {t('Starts in')}
      </span>
      <span
        aria-hidden
        className="flex items-start gap-2.5 font-mono text-[22px] leading-none tabular-nums text-foreground sm:text-[24px]"
      >
        {segments.map((segment, index) => (
          <Fragment key={segment.id}>
            {index > 0 ? (
              <span className="pt-0.5 text-muted-foreground/40">:</span>
            ) : null}
            <span className="flex flex-col items-center">
              <RollingDigits
                value={segment.value}
                reduceMotion={reduceMotion}
              />
              <span className="mt-1.5 font-sans text-[9px] font-medium uppercase tracking-[0.22em] text-muted-foreground/80">
                {segment.label}
              </span>
            </span>
          </Fragment>
        ))}
      </span>
    </div>
  )
}
