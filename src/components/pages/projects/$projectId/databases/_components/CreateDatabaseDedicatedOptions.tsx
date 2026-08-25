import { Minus, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import {
  calculateDedicatedDatabaseMonthlyCost,
  DEDICATED_DB_HA_REPLICA_OPTIONS,
  formatDedicatedAddonPrice,
  MAX_DEDICATED_DB_HA_REPLICA_COUNT,
  type DedicatedDatabaseCreatePricing,
} from '@/lib/database-create-pricing'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import { useT } from '@/lib/i18n/translate'

type CreateDatabaseDedicatedOptionsProps = {
  basePriceUsd: number
  pricing: DedicatedDatabaseCreatePricing
  replicaCount: number
  onReplicaCountChange: (count: number) => void
  pitrEnabled: boolean
  onPitrEnabledChange: (enabled: boolean) => void
}

function getReplicaOption(count: number) {
  return (
    DEDICATED_DB_HA_REPLICA_OPTIONS.find((option) => option.count === count) ??
    DEDICATED_DB_HA_REPLICA_OPTIONS[0]
  )
}

export function CreateDatabaseDedicatedOptions({
  basePriceUsd,
  pricing,
  replicaCount,
  onReplicaCountChange,
  pitrEnabled,
  onPitrEnabledChange,
}: CreateDatabaseDedicatedOptionsProps) {
  const t = useT()
  const replicaOption = getReplicaOption(replicaCount)
  const replicaAddonUsd = calculateDedicatedDatabaseMonthlyCost({
    basePriceUsd,
    replicaCount,
    pitrEnabled: false,
    pricing,
  }).haReplicasUsd

  const pitrCost = calculateDedicatedDatabaseMonthlyCost({
    basePriceUsd,
    replicaCount: 0,
    pitrEnabled: true,
    pricing,
  }).pitrUsd

  const haReplicaRatePercent = Math.round(pricing.haReplicaRate * 100)
  const pitrRatePercent = Math.round(pricing.pitrRate * 100)

  const decrementReplicas = () =>
    onReplicaCountChange(Math.max(0, replicaCount - 1))
  const incrementReplicas = () =>
    onReplicaCountChange(
      Math.min(MAX_DEDICATED_DB_HA_REPLICA_COUNT, replicaCount + 1),
    )

  return (
    <div className="space-y-8">
      <section>
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h2 className="text-[15px] font-semibold text-foreground">
              {t('Read replicas')}
            </h2>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t('Add read-only instances to scale query traffic and improve failover resilience alongside your primary database.')}{' '}
              {t('Each replica is billed at')} {haReplicaRatePercent}%{' '}
              {t('of your compute tier per month.')}
            </p>
          </div>

          <div className="border-t border-border" />

          <div className="px-6 py-4">
            <div className="flex items-start justify-between gap-6">
              <div className="min-w-0 flex-1 space-y-2">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                  <Label
                    htmlFor="db-replica-count"
                    className="text-[13px] font-medium text-foreground"
                  >
                    {t('Replica count')}
                  </Label>
                  <span className="text-[12px] text-muted-foreground">
                    {t(replicaOption.label)} · 0–{MAX_DEDICATED_DB_HA_REPLICA_COUNT}
                  </span>
                </div>
                <p className="text-[13px] leading-relaxed text-muted-foreground">
                  {t(replicaOption.description)}
                </p>
              </div>

              <div className="flex shrink-0 flex-col items-end gap-2">
                <div
                  id="db-replica-count"
                  className="flex items-center gap-1.5"
                  role="group"
                  aria-label={t('Replica count')}
                >
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8"
                    onClick={decrementReplicas}
                    disabled={replicaCount <= 0}
                    aria-label={t('Decrease replica count')}
                    data-analytics-track="manual"
                  >
                    <Minus className="size-3.5" />
                  </Button>
                  <span
                    className="flex size-8 items-center justify-center rounded-md border border-border bg-muted/40 text-[13px] font-semibold tabular-nums text-foreground"
                    aria-live="polite"
                    aria-atomic="true"
                  >
                    {replicaCount}
                  </span>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8"
                    onClick={incrementReplicas}
                    disabled={replicaCount >= MAX_DEDICATED_DB_HA_REPLICA_COUNT}
                    aria-label={t('Increase replica count')}
                    data-analytics-track="manual"
                  >
                    <Plus className="size-3.5" />
                  </Button>
                </div>
                <span className="text-[13px] font-semibold tabular-nums text-foreground">
                  {formatDedicatedAddonPrice(replicaAddonUsd)}
                </span>
              </div>
            </div>

            {replicaCount >= MAX_DEDICATED_DB_HA_REPLICA_COUNT && (
              <div className="mt-4 flex flex-col gap-3 rounded-lg border border-border bg-muted/30 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  {t('You have reached the maximum self-serve replica count. Contact sales if you need a custom high availability configuration.')}
                </p>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 shrink-0 text-[13px]"
                  asChild
                >
                  <a
                    href={CONTACT_ENTERPRISE_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {t('Contact sales')}
                  </a>
                </Button>
              </div>
            )}
          </div>
        </div>
      </section>

      <section>
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h2 className="text-[15px] font-semibold text-foreground">
              {t('Point-in-time recovery (PITR)')}
            </h2>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t('Restore your database to a specific moment in time, beyond the latest scheduled backup. Useful for recovering from accidental deletes, failed migrations, or bad writes.')}{' '}
              {t('Billed at')} {pitrRatePercent}%{' '}
              {t('of your compute tier per month when enabled.')}
            </p>
          </div>

          <div className="border-t border-border" />

          <div className="px-6 py-4">
            <div className="flex items-center justify-between gap-4">
              <Label
                htmlFor="db-pitr"
                className="text-[13px] font-medium text-foreground"
              >
                {t('Enable PITR')}
              </Label>
              <div className="flex shrink-0 flex-col items-end gap-2">
                <Switch
                  id="db-pitr"
                  checked={pitrEnabled}
                  onCheckedChange={onPitrEnabledChange}
                  data-analytics-track="manual"
                />
                <span className="text-[13px] font-semibold tabular-nums text-foreground">
                  {pitrEnabled
                    ? formatDedicatedAddonPrice(pitrCost)
                    : formatDedicatedAddonPrice(0)}
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}
