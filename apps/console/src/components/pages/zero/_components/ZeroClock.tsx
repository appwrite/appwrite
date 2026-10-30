'use client'

import { useEffect, useState } from 'react'
import { Slider } from '@/components/ui/slider'
import { useI18n } from '@/lib/i18n'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ZERO_BUILD_MINUTES,
  ZERO_CLAIM_HOURS,
  zeroStages,
  type ZeroStage,
} from '@/lib/zero/marketing-content'

const CLAIM_END_MINUTES = ZERO_BUILD_MINUTES + ZERO_CLAIM_HOURS * 60

/** Share of the track each stage takes. The hour gets room to be read, not 4% of a day. */
const BANDS: Record<ZeroStage['id'], number> = {
  build: 36,
  claim: 50,
  deleted: 14,
}

function minutesAt(position: number): number {
  if (position <= BANDS.build) {
    return Math.round((position / BANDS.build) * ZERO_BUILD_MINUTES)
  }
  const claimEnd = BANDS.build + BANDS.claim
  if (position <= claimEnd) {
    const share = (position - BANDS.build) / BANDS.claim
    return Math.round(
      ZERO_BUILD_MINUTES + share * (CLAIM_END_MINUTES - ZERO_BUILD_MINUTES),
    )
  }
  return CLAIM_END_MINUTES + 1
}

function stageAt(minutes: number): ZeroStage {
  if (minutes < ZERO_BUILD_MINUTES) return zeroStages[0]
  if (minutes <= CLAIM_END_MINUTES) return zeroStages[1]
  return zeroStages[2]
}

function formatDuration(minutes: number, language: string) {
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  const unit = (value: number, name: 'hour' | 'minute') =>
    new Intl.NumberFormat(language, {
      style: 'unit',
      unit: name,
      unitDisplay: 'narrow',
    }).format(value)
  if (hours === 0) return unit(rest, 'minute')
  return rest === 0
    ? unit(hours, 'hour')
    : `${unit(hours, 'hour')} ${unit(rest, 'minute')}`
}

export function ZeroClock() {
  const t = useT()
  const { language } = useI18n()
  const [position, setPosition] = useState(20)
  const [now, setNow] = useState<Date | null>(null)

  useEffect(() => {
    setNow(new Date())
    const timer = window.setInterval(() => setNow(new Date()), 30_000)
    return () => window.clearInterval(timer)
  }, [])

  const minutes = minutesAt(position)
  const stage = stageAt(minutes)

  const time = new Intl.DateTimeFormat(language, {
    hour: 'numeric',
    minute: '2-digit',
  })
  const day = new Intl.DateTimeFormat(language, {
    weekday: 'long',
    hour: 'numeric',
    minute: '2-digit',
  })
  const buildEnds = now
    ? time.format(new Date(now.getTime() + ZERO_BUILD_MINUTES * 60_000))
    : null
  const claimEnds = now
    ? day.format(new Date(now.getTime() + CLAIM_END_MINUTES * 60_000))
    : null

  return (
    <div className="grid grid-cols-1 gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:gap-16">
      <div>
        <dl className="flex flex-wrap gap-x-14 gap-y-5">
          <div className="flex flex-col-reverse gap-2">
            <dt className="text-[16px] text-muted-foreground">
              {t('Build until')}
            </dt>
            <dd className="font-aeonik-pro text-[32px] leading-none tabular-nums text-foreground sm:text-[40px]">
              {buildEnds ?? t('An hour from now')}
            </dd>
          </div>
          <div className="flex flex-col-reverse gap-2">
            <dt className="text-[16px] text-muted-foreground">
              {t('Claim by')}
            </dt>
            <dd className="font-aeonik-pro text-[32px] leading-none tabular-nums text-foreground sm:text-[40px]">
              {claimEnds ?? t('A day after that')}
            </dd>
          </div>
        </dl>

        <div className="mt-10">
          <Slider
            value={[position]}
            min={0}
            max={100}
            step={1}
            onValueChange={([value]) => setPosition(value ?? 0)}
            aria-label={t('Time since the project was created')}
            className="py-3 [&_[data-slot=slider-range]]:bg-[var(--brand-cta)] [&_[data-slot=slider-thumb]]:size-5 [&_[data-slot=slider-thumb]]:border-[var(--brand-cta)] [&_[data-slot=slider-track]]:h-2"
          />
          <ol className="mt-3 flex text-[13px]">
            {zeroStages.map((item) => (
              <li
                key={item.id}
                style={{ width: `${BANDS[item.id]}%` }}
                className={cn(
                  'pe-2 transition-colors',
                  item.id === 'deleted' &&
                    'flex justify-end whitespace-nowrap pe-0',
                  item.id === stage.id
                    ? 'text-foreground'
                    : 'text-muted-foreground',
                )}
              >
                {item.id === 'build'
                  ? t('First hour')
                  : item.id === 'claim'
                    ? t('Next 24 hours')
                    : t('After 25 hours')}
              </li>
            ))}
          </ol>
        </div>
      </div>

      <div className="rounded-xl bg-muted/40 p-6" aria-live="polite">
        <p className="text-[13px] tabular-nums text-muted-foreground">
          {t('Since creation')}:{' '}
          {stage.id === 'deleted'
            ? `${formatDuration(CLAIM_END_MINUTES, language)}+`
            : formatDuration(minutes, language)}
        </p>
        <h3 className="mt-2 font-aeonik-pro text-[24px] leading-tight text-foreground">
          {t(stage.title)}
        </h3>
        <p className="mt-3 text-[16px] leading-6 text-foreground">
          {t(stage.description)}
        </p>
      </div>
    </div>
  )
}
