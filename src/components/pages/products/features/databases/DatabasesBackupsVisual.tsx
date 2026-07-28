import { Clock3, ShieldCheck } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const BACKUP_POLICIES = [
  {
    id: 'hourly',
    title: 'Hourly',
    description: 'Runs every hour, retained for 24 hours',
    active: true,
  },
  {
    id: 'daily',
    title: 'Daily',
    description: 'Runs every day, retained for 7 days',
    active: true,
  },
] as const

export function DatabasesBackupsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: 'Overview' },
        { id: 'backups', label: 'Backups', active: true },
        { id: 'settings', label: 'Settings' },
      ]}
    >
      <div className="space-y-4">
        <div className="overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="flex items-start gap-3 px-4 py-3">
            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
              <ShieldCheck className="size-3.5 text-muted-foreground" aria-hidden />
            </span>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-[13px] font-semibold text-foreground">
                  {t('Backup policies')}
                </p>
                <Badge variant="info" className="text-[10px] shrink-0">
                  {t('Encrypted')}
                </Badge>
              </div>
              <p className="mt-1 text-[12px] leading-5 text-muted-foreground">
                {t('Hot backups with zero downtime and fast recovery.')}
              </p>
            </div>
          </div>
          <div className="divide-y divide-border border-t border-border">
            {BACKUP_POLICIES.map((policy) => (
              <div
                key={policy.id}
                className="flex items-center justify-between gap-3 px-4 py-2.5"
              >
                <div className="min-w-0">
                  <p className="text-[12px] font-medium text-foreground">{t(policy.title)}</p>
                  <p className="mt-0.5 text-[11px] text-muted-foreground">
                    {t(policy.description)}
                  </p>
                </div>
                <Switch
                  checked={policy.active}
                  disabled
                  className="shrink-0 data-[state=checked]:bg-foreground/80"
                  aria-hidden
                />
              </div>
            ))}
          </div>
        </div>

        <div className="overflow-hidden rounded-xl border border-border bg-background/80">
          <div className="flex items-center justify-between gap-3 px-4 py-3">
            <div className="flex min-w-0 items-start gap-3">
              <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                <Clock3 className="size-3.5 text-muted-foreground" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="text-[13px] font-semibold text-foreground">
                  {t('Point-in-time recovery (PITR)')}
                </p>
                <p className="mt-1 text-[12px] leading-5 text-muted-foreground">
                  {t('Restore to a specific moment beyond the latest backup.')}
                </p>
              </div>
            </div>
            <Switch
              checked
              disabled
              className="shrink-0 data-[state=checked]:bg-foreground/80"
              aria-hidden
            />
          </div>
          <div className="border-t border-border bg-muted/15 px-4 py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted-foreground">
              <span>{t('Retention')}</span>
              <span className="font-medium text-foreground">{t('7 days')}</span>
            </div>
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
