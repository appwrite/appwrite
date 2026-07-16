import { CheckCircle2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import { DedicatedDatabaseRegionUnavailableBadge } from '../_components/DedicatedDatabaseRegionUnavailableCard'
import {
  formatDedicatedDatabaseRegionUnavailableDescription,
  projectSupportsDedicatedDatabaseCompute,
} from '@/lib/databases/dedicated-database-regions'
import {
  SERVERLESS_DATABASE_SPEC_ID,
  TABLE_DB_SPEC_OPTIONS,
  hasLockedDatabaseSpecifications,
} from '@/lib/database-specs'
import { useProject } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const CURRENT_TIER_ID = SERVERLESS_DATABASE_SPEC_ID

type DatabaseSpecificationCardProps = {
  projectId: string
}

export function DatabaseSpecificationCard({
  projectId,
}: DatabaseSpecificationCardProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const supportsDedicatedDatabaseCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)

  if (!supportsDedicatedDatabaseCompute) {
    return (
      <div
        data-card-id="specification"
        className="rounded-xl border border-border bg-card/50 overflow-hidden opacity-80"
      >
        <div className="px-6 py-4">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Specification')}
            </h3>
            <DedicatedDatabaseRegionUnavailableBadge />
          </div>
          <p className="text-[13px] text-muted-foreground mt-2">
            {formatDedicatedDatabaseRegionUnavailableDescription(t)}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div
      data-card-id="specification"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Specification')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Current tier: Serverless. Dedicated tiers are coming soon.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border bg-muted/40">
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Tier')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              CPU
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Memory')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Connections')}
            </TableHead>
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[180px]">
              {t('Price')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {TABLE_DB_SPEC_OPTIONS.map((spec) => {
            const isCurrent = spec.id === CURRENT_TIER_ID
            const locked = spec.comingSoon === true
            return (
              <TableRow
                key={spec.id}
                className={cn(
                  'border-b border-border last:border-b-0 transition-colors',
                  isCurrent && 'bg-primary/5',
                )}
              >
                <TableCell className="px-6 py-3">
                  <span className="flex items-center gap-2 flex-wrap">
                    <span className="text-[13px] font-medium text-foreground">
                      {t(spec.label)}
                    </span>
                    {isCurrent && (
                      <Badge
                        variant="success"
                        className="gap-1 text-[10px] shrink-0"
                      >
                        <CheckCircle2 className="h-3 w-3" />
                        {t('Current')}
                      </Badge>
                    )}
                    {locked && (
                      <Badge
                        variant="inactive"
                        className="text-[10px] shrink-0"
                      >
                        {t('Coming soon')}
                      </Badge>
                    )}
                  </span>
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                  {spec.cpu}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                  {spec.memory}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] tabular-nums text-muted-foreground">
                  {spec.connections}
                </TableCell>
                <TableCell className="px-6 py-3 text-end">
                  {locked ? (
                    <span className="text-[13px] text-muted-foreground">
                      {spec.price}
                    </span>
                  ) : (
                    <span className="text-[13px] font-medium tabular-nums text-foreground">
                      {spec.price}
                    </span>
                  )}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
      {hasLockedDatabaseSpecifications(TABLE_DB_SPEC_OPTIONS) && (
        <div className="px-6 py-3">
          <SpecificationsUpgradeNote
            orgId={project?.teamId}
            showContactSales
          />
        </div>
      )}
    </div>
  )
}
