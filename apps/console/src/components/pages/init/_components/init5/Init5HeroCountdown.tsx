import { AnimatePresence, motion } from 'motion/react'
import { Fragment, useEffect, useMemo, useState } from 'react'
import { resolveInitDayUnlockDate } from '@/lib/init/dates'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

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
    // Clip the roll vertically with a soft fade, and leave room around the
    // digits so their glow is never cut into a box.
    <span className="-mx-4 -my-3 inline-flex overflow-hidden px-4 py-3 [mask-image:linear-gradient(to_bottom,transparent,#000_12px,#000_calc(100%-12px),transparent)]">
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
      className="flex flex-col items-center gap-3 lg:items-start"
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
      {/*
        A comet of the hero's light circles the panel (see `.init5-light-frame`).
        Hover brightens its halo, a spotlight follows the cursor, and the
        digits under it lift and glow.
      */}
      <div
        aria-hidden
        onPointerMove={(event) => {
          const box = event.currentTarget.getBoundingClientRect()
          event.currentTarget.style.setProperty(
            '--init5-spot-x',
            `${event.clientX - box.left}px`,
          )
          event.currentTarget.style.setProperty(
            '--init5-spot-y',
            `${event.clientY - box.top}px`,
          )
        }}
        className="init5-light-frame group/counter flex rounded-2xl bg-background/75 backdrop-blur-md transition-shadow duration-500 hover:[--init5-trail-glow:0.9] hover:shadow-[0_18px_50px_-24px_color-mix(in_oklch,var(--brand-cta)_70%,transparent)]"
      >
        <span aria-hidden className="init5-light-halo" />
        <span
          aria-hidden
          className="pointer-events-none absolute inset-0 rounded-[inherit] bg-[radial-gradient(150px_circle_at_var(--init5-spot-x,50%)_var(--init5-spot-y,50%),color-mix(in_oklch,var(--brand-cta)_16%,transparent),transparent_70%)] opacity-0 transition-opacity duration-300 group-hover/counter:opacity-100"
        />
        <span
          aria-hidden
          className="pointer-events-none absolute inset-x-6 top-0 h-px bg-gradient-to-r from-transparent via-[color-mix(in_oklch,var(--brand-cta)_55%,transparent)] to-transparent transition-[left,right] duration-500 group-hover/counter:inset-x-2"
        />
        {segments.map((segment, index) => (
          <Fragment key={segment.id}>
            {index > 0 ? (
              <span className="relative my-3 w-px bg-gradient-to-b from-transparent via-border to-transparent" />
            ) : null}
            <span className="group/cell relative flex min-w-[64px] flex-col items-center px-3 py-3 sm:min-w-[76px] sm:px-4 sm:py-3.5">
              <span
                className={cn(
                  'font-mono text-[26px] font-light leading-none tabular-nums transition-[transform,text-shadow] duration-300 ease-out sm:text-[30px]',
                  '[text-shadow:0_0_12px_color-mix(in_oklch,var(--brand-cta)_40%,transparent)]',
                  'group-hover/cell:-translate-y-0.5 group-hover/cell:[text-shadow:0_0_14px_color-mix(in_oklch,var(--brand-cta)_80%,transparent)] motion-reduce:group-hover/cell:translate-y-0',
                  segment.id === 'seconds'
                    ? 'text-[var(--brand-cta)]'
                    : 'text-foreground',
                )}
              >
                <RollingDigits
                  value={segment.value}
                  reduceMotion={reduceMotion}
                />
              </span>
              <span className="mt-2 text-[9px] font-medium uppercase tracking-[0.24em] text-muted-foreground/80 transition-colors duration-300 group-hover/cell:text-foreground">
                {segment.label}
              </span>
            </span>
          </Fragment>
        ))}
      </div>
    </div>
  )
}
