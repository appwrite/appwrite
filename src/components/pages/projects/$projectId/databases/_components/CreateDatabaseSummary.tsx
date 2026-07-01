import {
  Braces,
  Layers,
  Table as TableIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import type { SpecOption } from '@/lib/database-specs'
import {
  DATABASE_COMPUTE_CREDITS_NOTE,
  type DedicatedDatabaseMonthlyCost,
} from '@/lib/database-create-pricing'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from './database-mascot-icons'

type DbTypeMeta = {
  id: string
  label: string
  icon: 'table' | 'braces' | 'layers' | 'elephant' | 'dolphin'
  comingSoon?: boolean
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
  canCreate: boolean
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
  canCreate,
}: CreateDatabaseSummaryProps) {
  const trimmedName = name.trim()
  const hasType = Boolean(selectedDbType && dbType)
  const showPricing = Boolean(
    showDedicatedOptions && selectedSpec && monthlyCost,
  )

  return (
    <div className="rounded-xl border border-border bg-card overflow-hidden">
      <div className="border-b border-border bg-muted/30 px-4 py-3">
        <h3 className="text-[13px] font-semibold tracking-tight text-foreground">
          Database summary
        </h3>
      </div>

      <div className="space-y-4 px-4 py-4">
        <InlineRow label="Name">
          {trimmedName ? (
            <span className="font-medium">{trimmedName}</span>
          ) : (
            <span className="text-muted-foreground">Required</span>
          )}
        </InlineRow>

        <InlineRow label="ID">
          {databaseId?.trim() ? (
            <span className="break-all font-mono text-[12px] font-medium">
              {databaseId.trim()}
            </span>
          ) : (
            <span className="text-muted-foreground">Auto-generated</span>
          )}
        </InlineRow>

        <InlineRow label="Type">
          {hasType ? (
            <span className="inline-flex items-center justify-end gap-1.5 font-medium">
              <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <DbTypeIcon icon={selectedDbType!.icon} />
              </span>
              {selectedDbType!.label}
              {(selectedDbType!.id === 'DocumentsDB' ||
                selectedDbType!.id === 'VectorsDB') && (
                <Badge variant="info" className="text-[10px] shrink-0">
                  Beta
                </Badge>
              )}
            </span>
          ) : (
            <span className="text-muted-foreground">Not selected</span>
          )}
        </InlineRow>

        {showSpecs && selectedSpec && (
          <div className="rounded-lg border border-border bg-muted/20 px-4 py-3 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <span className="text-[13px] font-semibold text-foreground">
                {selectedSpec.label}
              </span>
              <span className="text-[13px] font-semibold tabular-nums text-foreground">
                {selectedSpec.price}
              </span>
            </div>
            <div className="space-y-2.5">
              <InlineRow label="CPU">
                <span className="font-medium tabular-nums">{selectedSpec.cpu}</span>
              </InlineRow>
              <InlineRow label="Memory">
                <span className="font-medium tabular-nums">{selectedSpec.memory}</span>
              </InlineRow>
              <InlineRow label="Connections">
                <span className="font-medium tabular-nums">
                  {selectedSpec.connections}
                </span>
              </InlineRow>
            </div>

            {showPricing && monthlyCost && (
              <>
                <div className="border-t border-border/80 pt-3 space-y-2">
                  <CostLine label="Compute" amountUsd={monthlyCost.baseUsd} />
                  <CostLine
                    label={
                      replicaCount === 0
                        ? 'Replicas'
                        : `Replicas (${replicaCount})`
                    }
                    amountUsd={monthlyCost.haReplicasUsd}
                    zeroLabel="None"
                  />
                  <CostLine
                    label={pitrEnabled ? 'PITR' : 'PITR (off)'}
                    amountUsd={monthlyCost.pitrUsd}
                    zeroLabel="Off"
                  />
                  <CostLine
                    label="Total"
                    amountUsd={monthlyCost.totalUsd}
                    emphasize
                  />
                </div>
                <div className="space-y-1.5 text-[12px] leading-relaxed text-muted-foreground">
                  <p>{DATABASE_COMPUTE_CREDITS_NOTE}</p>
                  <p>Storage and bandwidth overages billed separately.</p>
                </div>
              </>
            )}
          </div>
        )}

        {showSpecs && !selectedSpec && (
          <p className="text-[12px] text-muted-foreground">
            Select a compute tier to continue.
          </p>
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
              Ready to create
            </span>
          ) : hasType && selectedDbType?.comingSoon ? (
            'This database type is not available yet.'
          ) : (
            'Complete the required fields to continue.'
          )}
        </p>
      </div>
    </div>
  )
}
