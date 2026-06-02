import {
  Braces,
  Layers,
  Table as TableIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import type { SpecOption } from '@/lib/database-specs'
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
  canCreate: boolean
}

function SummaryRow({
  label,
  children,
  className,
}: {
  label: string
  children: React.ReactNode
  className?: string
}) {
  return (
    <div className={cn('space-y-1.5', className)}>
      <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      {children}
    </div>
  )
}

function DbTypeIcon({
  icon,
  className,
}: {
  icon: DbTypeMeta['icon']
  className?: string
}) {
  const iconClass = cn('h-4 w-4 shrink-0', className)
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

function SpecDetail({
  label,
  value,
  mono,
}: {
  label: string
  value: string
  mono?: boolean
}) {
  return (
    <div className="flex items-start justify-between gap-4 text-[13px] leading-snug">
      <span className="text-muted-foreground">{label}</span>
      <span
        className={cn(
          'shrink-0 text-right font-medium text-foreground',
          mono && 'font-mono text-[12px]',
        )}
      >
        {value}
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
  canCreate,
}: CreateDatabaseSummaryProps) {
  const trimmedName = name.trim()
  const hasType = Boolean(selectedDbType && dbType)

  return (
    <div className="rounded-xl border border-border bg-card overflow-hidden">
      <div className="border-b border-border bg-muted/30 px-5 py-3.5">
        <h3 className="text-[13px] font-semibold tracking-tight text-foreground">
          Database summary
        </h3>
        <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
          Review your configuration before creating the database.
        </p>
      </div>

      <div className="px-5 py-5 space-y-5">
        <SummaryRow label="Name">
          {trimmedName ? (
            <p className="text-[14px] font-medium leading-snug text-foreground">
              {trimmedName}
            </p>
          ) : (
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              Enter a name for your database.
            </p>
          )}
        </SummaryRow>

        <SummaryRow label="Database ID">
          {databaseId?.trim() ? (
            <p className="break-all font-mono text-[13px] font-medium leading-snug text-foreground">
              {databaseId.trim()}
            </p>
          ) : (
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              Auto-generated on create
            </p>
          )}
        </SummaryRow>

        <div className="space-y-5 border-t border-border pt-5">
          <SummaryRow label="Database type">
            {hasType ? (
              <div className="flex flex-wrap items-center gap-2">
                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <DbTypeIcon icon={selectedDbType!.icon} />
                </span>
                <span className="text-[14px] font-medium text-foreground">
                  {selectedDbType!.label}
                </span>
                {(selectedDbType!.id === 'DocumentsDB' ||
                  selectedDbType!.id === 'VectorsDB') && (
                  <Badge variant="info" className="text-[10px] shrink-0">
                    Beta
                  </Badge>
                )}
                {selectedDbType!.comingSoon && (
                  <Badge variant="inactive" className="text-[10px] shrink-0">
                    Coming soon
                  </Badge>
                )}
              </div>
            ) : (
              <p className="text-[13px] leading-relaxed text-muted-foreground">
                Choose a database type to continue.
              </p>
            )}
          </SummaryRow>

          {showSpecs && (
            <SummaryRow label="Specification">
              {selectedSpec ? (
                <div className="space-y-3 rounded-lg border border-border bg-muted/20 px-4 py-3.5">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-[14px] font-semibold text-foreground">
                      {selectedSpec.label}
                    </span>
                    <span className="text-[13px] font-semibold tabular-nums text-foreground">
                      {selectedSpec.price}
                    </span>
                  </div>
                  <div className="space-y-2.5 border-t border-border/80 pt-3">
                    <SpecDetail label="CPU" value={selectedSpec.cpu} />
                    <SpecDetail label="Memory" value={selectedSpec.memory} />
                    <SpecDetail
                      label="Connections"
                      value={selectedSpec.connections}
                      mono={selectedSpec.connections !== 'Shared'}
                    />
                  </div>
                </div>
              ) : (
                <p className="text-[13px] leading-relaxed text-muted-foreground">
                  Select a compute and storage tier.
                </p>
              )}
            </SummaryRow>
          )}
        </div>
      </div>

      <div
        className={cn(
          'border-t px-5 py-3.5',
          canCreate ? 'bg-muted/30' : 'bg-transparent',
        )}
      >
        <p
          className={cn(
            'text-[12px] leading-relaxed',
            canCreate ? 'text-foreground' : 'text-muted-foreground',
          )}
        >
          {canCreate
            ? 'Your database is ready to create.'
            : hasType && selectedDbType?.comingSoon
              ? 'This database type is not available yet.'
              : 'Complete the required fields to create your database.'}
        </p>
      </div>
    </div>
  )
}
