import {
  Braces,
  Layers,
  Table as TableIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'
import { isServerlessDatabaseSpecId, type SpecOption } from '@/lib/database-specs'
import {
  formatDedicatedMonthlyPrice,
  type DedicatedDatabaseMonthlyCost,
} from '@/lib/database-create-pricing'
import {
  formatDatabaseOperationOverageRate,
  getPlanDatabaseOperationLimits,
  getPlanDatabaseOperationOverage,
  type PlanDatabaseOperationLimit,
} from '@/lib/databases/dedicated-database-plan'
import { formatCompactCount } from '@/lib/usage/format-metric'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from './database-mascot-icons'
import { useT } from '@/lib/i18n/translate'

type DbTypeMeta = {
  id: string
  label: string
  icon: 'table' | 'braces' | 'layers' | 'elephant' | 'dolphin'
  comingSoon?: boolean
  requiresUpgrade?: boolean
}

type CreateDatabaseSummaryProps = {
  name: string
  databaseId?: string
  dbType: string | null
  selectedDbType: DbTypeMeta | null
  showSpecs: boolean
  selectedSpec: SpecOption | null
  showDedicatedOptions?: boolean
  replicaCount?: number
  pitrEnabled?: boolean
  monthlyCost?: DedicatedDatabaseMonthlyCost | null
  showBackupPolicies?: boolean
  backupPoliciesLabel?: string | null
  backupsEnabled?: boolean
  canCreate: boolean
  computeCreditsUsd?: number | null
  organizationPlan?: Models.BillingPlan | null
}

function DbTypeIcon({
  icon,
  className,
}: {
  icon: DbTypeMeta['icon']
  className?: string
}) {
  const iconClass = cn('h-3.5 w-3.5 shrink-0', className)
  switch (icon) {
    case 'table':
      return <TableIcon className={iconClass} />
    case 'braces':
      return <Braces className={iconClass} />
    case 'layers':
      return <Layers className={iconClass} />
    case 'elephant':
      return <PostgresElephantIcon className={iconClass} />
    case 'dolphin':
      return <MySQLDolphinIcon className={iconClass} />
    default:
      return null
  }
}

function InlineRow({
  label,
  children,
}: {
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="flex items-start justify-between gap-3 text-[13px] leading-snug">
      <span className="shrink-0 text-muted-foreground">{label}</span>
      <div className="min-w-0 text-end text-foreground">{children}</div>
    </div>
  )
}

function formatIncludedOpsValue(
  value: PlanDatabaseOperationLimit,
  t: (text: string) => string,
): string {
  if (value === 'unlimited') return t('Unlimited')
  return `${formatCompactCount(value)} ${t('included')}`
}

function ServerlessPlanOps({
  plan,
}: {
  plan: Models.BillingPlan | null | undefined
}) {
  const t = useT()
  const { reads, writes } = getPlanDatabaseOperationLimits(plan)
  const overage = getPlanDatabaseOperationOverage(plan)
  const showOverage = overage.reads != null || overage.writes != null

  return (
    <div className="space-y-2.5">
      <InlineRow label={t('Compute')}>
        <span className="text-muted-foreground">{t('No compute fee')}</span>
      </InlineRow>
      {reads != null ? (
        <InlineRow label={t('Reads')}>
          <span className="font-medium tabular-nums">
            {formatIncludedOpsValue(reads, t)}
          </span>
        </InlineRow>
      ) : null}
      {writes != null ? (
        <InlineRow label={t('Writes')}>
          <span className="font-medium tabular-nums">
            {formatIncludedOpsValue(writes, t)}
          </span>
        </InlineRow>
      ) : null}
      {showOverage ? (
        <div className="border-t border-border/80 pt-3 space-y-2.5">
          {overage.reads ? (
            <InlineRow label={t('Additional reads')}>
              <span className="font-medium tabular-nums">
                {formatDatabaseOperationOverageRate(overage.reads)}
              </span>
            </InlineRow>
          ) : null}
          {overage.writes ? (
            <InlineRow label={t('Additional writes')}>
              <span className="font-medium tabular-nums">
                {formatDatabaseOperationOverageRate(overage.writes)}
              </span>
            </InlineRow>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

function CostLine({
  label,
  amountUsd,
  emphasize,
  zeroLabel,
}: {
  label: string
  amountUsd: number
  emphasize?: boolean
  /** Shown instead of a dollar amount when the line cost is zero (e.g. None, Off). */
  zeroLabel?: string
}) {
  const isUnset = amountUsd <= 0 && zeroLabel !== undefined
  const display = isUnset
    ? zeroLabel
    : `${formatCurrency(amountUsd)}/mo`

  return (
    <div
      className={cn(
        'flex items-center justify-between gap-3 text-[13px] leading-snug',
        emphasize && 'pt-2 border-t border-border/80',
      )}
    >
      <span className={emphasize ? 'font-medium text-foreground' : 'text-muted-foreground'}>
        {label}
      </span>
      <span
        className={cn(
          'shrink-0 tabular-nums font-medium',
          emphasize && 'font-semibold',
          isUnset && 'text-muted-foreground',
        )}
      >
        {display}
      </span>
    </div>
  )
}

export function CreateDatabaseSummary({
  name,
  databaseId,
  dbType,
  selectedDbType,
  showSpecs,
  selectedSpec,
  showDedicatedOptions = false,
  replicaCount = 0,
  pitrEnabled = false,
  monthlyCost = null,
  showBackupPolicies = false,
  backupPoliciesLabel = null,
  backupsEnabled,
  canCreate,
  computeCreditsUsd = null,
  organizationPlan = null,
}: CreateDatabaseSummaryProps) {
  const t = useT()
  const trimmedName = name.trim()
  const hasType = Boolean(selectedDbType && dbType)
  const showPricing = Boolean(
    showDedicatedOptions && selectedSpec && monthlyCost,
  )

  return (
    <div className="rounded-xl border border-border bg-card overflow-hidden">
      <div className="border-b border-border bg-muted/30 px-4 py-3">
        <h3 className="text-[13px] font-semibold tracking-tight text-foreground">
          {t('Database summary')}
        </h3>
      </div>

      <div className="space-y-4 px-4 py-4">
        <InlineRow label={t('Name')}>
          {trimmedName ? (
            <span className="font-medium">{trimmedName}</span>
          ) : (
            <span className="text-muted-foreground">{t('Required')}</span>
          )}
        </InlineRow>

        <InlineRow label="ID">
          {databaseId?.trim() ? (
            <span className="break-all font-mono text-[12px] font-medium">
              {databaseId.trim()}
            </span>
          ) : (
            <span className="text-muted-foreground">{t('Auto-generated')}</span>
          )}
        </InlineRow>

        <InlineRow label={t('Type')}>
          {hasType ? (
            <span className="inline-flex items-center justify-end gap-1.5 font-medium">
              <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <DbTypeIcon icon={selectedDbType!.icon} />
              </span>
              {selectedDbType!.label}
              {(selectedDbType!.id === 'DocumentsDB' ||
                selectedDbType!.id === 'VectorsDB') && (
                <Badge variant="info" className="text-[10px] shrink-0">
                  {t('Beta')}
                </Badge>
              )}
            </span>
          ) : (
            <span className="text-muted-foreground">{t('Not selected')}</span>
          )}
        </InlineRow>

        {showSpecs && selectedSpec && (
          <div className="rounded-lg border border-border bg-muted/20 px-4 py-3 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <span className="text-[13px] font-semibold text-foreground">
                {t(selectedSpec.label)}
              </span>
              {isServerlessDatabaseSpecId(selectedSpec.id) ? null : (
                <span className="text-[13px] font-semibold tabular-nums text-foreground">
                  {selectedSpec.price}
                </span>
              )}
            </div>
            {isServerlessDatabaseSpecId(selectedSpec.id) ? (
              <ServerlessPlanOps plan={organizationPlan} />
            ) : (
              <div className="space-y-2.5">
                <InlineRow label="CPU">
                  <span className="font-medium tabular-nums">{selectedSpec.cpu}</span>
                </InlineRow>
                <InlineRow label={t('Memory')}>
                  <span className="font-medium tabular-nums">{selectedSpec.memory}</span>
                </InlineRow>
                <InlineRow label={t('Connections')}>
                  <span className="font-medium tabular-nums">
                    {selectedSpec.connections}
                  </span>
                </InlineRow>
              </div>
            )}

            {showPricing && monthlyCost && (
              <>
                <div className="border-t border-border/80 pt-3 space-y-2">
                  <CostLine label={t('Compute')} amountUsd={monthlyCost.baseUsd} />
                  <CostLine
                    label={
                      replicaCount === 0
                        ? t('Replicas')
                        : `${t('Replicas')} (${replicaCount})`
                    }
                    amountUsd={monthlyCost.haReplicasUsd}
                    zeroLabel={t('None')}
                  />
                  <CostLine
                    label={pitrEnabled ? 'PITR' : `PITR (${t('off')})`}
                    amountUsd={monthlyCost.pitrUsd}
                    zeroLabel={t('Off')}
                  />
                  <CostLine
                    label={t('Total')}
                    amountUsd={monthlyCost.totalUsd}
                    emphasize
                  />
                </div>
                <div className="space-y-2.5 text-[13px]">
                  {computeCreditsUsd != null && computeCreditsUsd > 0 ? (
                    <InlineRow label={t('Compute credits')}>
                      <span className="font-medium tabular-nums">
                        {formatDedicatedMonthlyPrice(computeCreditsUsd)}{' '}
                        {t('included')}
                      </span>
                    </InlineRow>
                  ) : null}
                  <p className="text-[12px] text-muted-foreground">
                    {t('Storage and bandwidth overages billed separately.')}
                  </p>
                </div>
              </>
            )}
          </div>
        )}

        {showSpecs && !selectedSpec && (
          <p className="text-[12px] text-muted-foreground">
            {t('Select a compute tier to continue.')}
          </p>
        )}

        {showBackupPolicies && (
          <InlineRow label={t('Backup policies')}>
            {backupsEnabled === false ? (
              <span className="text-muted-foreground">{t('Not included')}</span>
            ) : backupPoliciesLabel ? (
              <span className="font-medium">{backupPoliciesLabel}</span>
            ) : (
              <span className="text-muted-foreground">{t('None')}</span>
            )}
          </InlineRow>
        )}
      </div>

      <div
        className={cn(
          'border-t px-4 py-2.5',
          canCreate ? 'bg-muted/30' : 'bg-transparent',
        )}
      >
        <p
          className={cn(
            'text-[12px] leading-snug',
            canCreate ? 'text-foreground' : 'text-muted-foreground',
          )}
        >
          {canCreate ? (
            <span className="inline-flex items-center gap-1.5">
              <span
                className="h-1.5 w-1.5 shrink-0 rounded-full bg-green-500"
                aria-hidden
              />
              {t('Ready to create')}
            </span>
          ) : hasType && selectedDbType?.comingSoon ? (
            t('This database type is not available yet.')
          ) : hasType && selectedDbType?.requiresUpgrade ? (
            t('Upgrade your plan to create this database type.')
          ) : (
            t('Complete the required fields to continue.')
          )}
        </p>
      </div>
    </div>
  )
}
