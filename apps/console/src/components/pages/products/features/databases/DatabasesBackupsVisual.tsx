import { Check, Clock3, DatabaseBackup, Lock, RotateCcw } from 'lucide-react'
import type { CSSProperties } from 'react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  ArtChip,
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const BACKUP_POLICIES = [
  { id: 'hourly', title: 'Hourly', description: 'Runs every hour, retained for 24 hours' },
  { id: 'daily', title: 'Daily', description: 'Runs every day, retained for 7 days' },
] as const

const DAYS = ['Sep 26', 'Sep 27', 'Sep 28', 'Sep 29', 'Sep 30', 'Oct 1', 'Oct 2'] as const

/** Position of the selected restore point along the recovery window (0-1). */
const RESTORE_AT = 0.77

function RecoveryTimeline() {
  const t = useT()
  return (
    <div className="relative pt-16">
      <div
        className="product-hero-rise absolute top-0 z-[2] -translate-x-3/4 rtl:translate-x-3/4"
        style={riseStyle(1000, { insetInlineStart: `${RESTORE_AT * 100}%` })}
      >
        <div className="product-tone-shadow rounded-lg border border-[rgb(var(--tone-rgb)/0.45)] bg-background px-2.5 py-1.5 dark:bg-card">
          <p className="whitespace-nowrap text-[10px] text-muted-foreground">{t('Restore time')}</p>
          <p dir="ltr" className="whitespace-nowrap font-mono text-[11px] font-medium text-foreground">
            Sep 30, 14:32:05 UTC
          </p>
        </div>
      </div>

      <div className="relative h-8">
        <div className="absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 overflow-hidden rounded-full bg-foreground/[0.07]">
          <div
            className="product-hero-fill h-full w-full rounded-full bg-[linear-gradient(90deg,rgb(var(--tone-rgb)/0.15),rgb(var(--tone-rgb)/0.55))] rtl:bg-[linear-gradient(270deg,rgb(var(--tone-rgb)/0.15),rgb(var(--tone-rgb)/0.55))]"
            style={{ '--fill-delay': '300ms', '--fill-duration': '2s' } as CSSProperties}
          />
        </div>

        {DAYS.map((day, index) => (
          <span
            key={day}
            className="product-hero-rise absolute top-1/2 size-2.5 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px] border border-foreground/30 bg-background rtl:translate-x-1/2 dark:bg-card"
            style={riseStyle(400 + index * 90, { insetInlineStart: `${(index / (DAYS.length - 1)) * 100}%` })}
            aria-hidden
          />
        ))}

        <span
          className="absolute -top-6 bottom-0 w-px bg-[var(--tone-ink)]"
          style={{ insetInlineStart: `${RESTORE_AT * 100}%` }}
          aria-hidden
        />
        <span
          className="absolute top-1/2 flex size-4 -translate-x-1/2 -translate-y-1/2 items-center justify-center rtl:translate-x-1/2"
          style={{ insetInlineStart: `${RESTORE_AT * 100}%` }}
          aria-hidden
        >
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-[rgb(var(--tone-rgb)/0.4)] motion-reduce:animate-none" />
          <span className="relative size-3 rounded-full border-2 border-[var(--tone-ink)] bg-background dark:bg-card" />
        </span>
      </div>

      <div className="mt-2 hidden justify-between font-mono text-[10px] text-muted-foreground sm:flex">
        {DAYS.slice(0, -1).map((day) => (
          <span key={day} dir="ltr">
            {day}
          </span>
        ))}
        <span className="font-sans">{t('Now')}</span>
      </div>
      <div className="mt-2 flex justify-between text-[10px] text-muted-foreground sm:hidden">
        <span dir="ltr" className="font-mono">
          {DAYS[0]}
        </span>
        <span>{t('Now')}</span>
      </div>
    </div>
  )
}

export function DatabasesBackupsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-4">
      <ArtPanel className="relative z-[1] w-full sm:w-[300px]" innerClassName="p-3.5" delayMs={60} float>
        <div className="flex items-center gap-2.5">
          <ArtIconBadge icon={DatabaseBackup} />
          <p className="text-[13px] font-semibold text-foreground">{t('Backup policies')}</p>
          <Badge variant="info" className="ms-auto shrink-0 text-[10px]">
            {t('Encrypted')}
          </Badge>
        </div>
        <p className="mt-2 text-[11px] leading-5 text-muted-foreground">
          {t('Hot backups with zero downtime and fast recovery.')}
        </p>
        <div className="mt-2.5 space-y-2">
          {BACKUP_POLICIES.map((policy, index) => (
            <div
              key={policy.id}
              className="product-hero-rise flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 px-2.5 py-2"
              style={riseStyle(250 + index * 120)}
            >
              <div className="min-w-0">
                <p className="text-[12px] font-medium text-foreground">{t(policy.title)}</p>
                <p className="text-[10px] leading-4 text-muted-foreground">{t(policy.description)}</p>
              </div>
              <Switch checked disabled className="scale-75 data-[state=checked]:bg-foreground/80" aria-hidden />
            </div>
          ))}
        </div>
      </ArtPanel>

      <ArtChip className="end-0 top-6 hidden sm:block" delayMs={700} floatDelayMs={500}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Check} tone="success" />
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Manual backup')}</p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              02:14 UTC · 1.8 GB
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="end-[8%] top-[92px] hidden sm:block" delayMs={850} floatDelayMs={1300}>
        <div className="flex items-center gap-1.5">
          <Lock className="size-3 text-muted-foreground" aria-hidden />
          <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
            AES-256
          </span>
        </div>
      </ArtChip>

      <div className="product-hero-rise mt-10" style={riseStyle(500)}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="flex items-center gap-2 text-[12px] font-semibold text-foreground">
            <Clock3 className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
            {t('Point-in-time recovery (PITR)')}
          </p>
          <p className="text-[11px] text-muted-foreground">
            {t('Retention')} <span className="font-medium text-foreground">{t('7 days')}</span>
          </p>
        </div>
        <p className="mt-1 text-[11px] text-muted-foreground">
          {t('Restore to a specific moment beyond the latest backup.')}
        </p>
        <div className="mt-2">
          <RecoveryTimeline />
        </div>
      </div>

      <ArtPanel
        className="mt-6 w-fit max-w-full sm:ms-auto"
        innerClassName="flex items-center gap-2.5 px-3 py-2"
        delayMs={1300}
        float
        floatDelayMs={800}
      >
        <ArtIconBadge icon={RotateCcw} tone="success" />
        <div className="min-w-0">
          <p className="text-[12px] font-medium text-foreground">{t('Restore completed')}</p>
          <p dir="ltr" className="truncate font-mono text-[10px] text-muted-foreground">
            orders-prod · 14:32:05 UTC
          </p>
        </div>
      </ArtPanel>
    </div>
  )
}
