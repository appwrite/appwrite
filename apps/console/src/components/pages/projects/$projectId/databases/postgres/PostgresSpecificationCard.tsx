import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useDatabaseAdminOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import {
  formatDedicatedSpecCpu,
  formatDedicatedSpecMemory,
  formatDedicatedSpecStorage,
  mapDedicatedDatabaseSpecifications,
  type SpecOption,
} from '@/lib/database-specs'
import { postgresNav } from '@/lib/postgres-database-routes'
import {
  POSTGRES_DATABASE_SPECS_SOURCE,
  useDatabaseSpecifications,
  usePostgresDatabase,
  useProject,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import { cn } from '@/lib/utils'
import { useMemo } from 'react'
import { useT } from '@/lib/i18n/translate'

type PostgresSpecificationCardProps = {
  projectId: string
  databaseId: string
}

function getNextEnabledSpec(
  specs: SpecOption[],
  currentSlug: string | undefined,
): SpecOption | undefined {
  if (specs.length === 0) return undefined
  const currentIndex = currentSlug
    ? specs.findIndex((spec) => spec.id === currentSlug)
    : -1
  const start = currentIndex >= 0 ? currentIndex + 1 : 0
  return specs.slice(start).find((spec) => !spec.comingSoon)
}

function getNextLockedSpec(
  specs: SpecOption[],
  currentSlug: string | undefined,
): SpecOption | undefined {
  if (specs.length === 0) return undefined
  const currentIndex = currentSlug
    ? specs.findIndex((spec) => spec.id === currentSlug)
    : -1
  const start = currentIndex >= 0 ? currentIndex + 1 : 0
  return specs.slice(start).find((spec) => spec.comingSoon === true)
}

function SpecMetric({
  label,
  value,
  className,
}: {
  label: string
  value: string
  className?: string
}) {
  return (
    <div className={cn('min-w-0 px-3 py-2', className)}>
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <p className="mt-0.5 truncate text-[12px] font-medium tabular-nums text-foreground">
        {value}
      </p>
    </div>
  )
}

function SpecCardSkeleton() {
  return (
    <div className="shrink-0 border-t border-border bg-background px-2.5 py-2">
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-3 py-2.5">
          <div className="space-y-2">
            <div className="h-2.5 w-16 animate-pulse rounded bg-muted" />
            <div className="h-3.5 w-24 animate-pulse rounded bg-muted" />
          </div>
        </div>
        <div className="border-t border-border">
          <div className="grid grid-cols-3 divide-x divide-border">
            <div className="px-3 py-2">
              <div className="h-2.5 w-8 animate-pulse rounded bg-muted" />
              <div className="mt-1.5 h-3 w-14 animate-pulse rounded bg-muted" />
            </div>
            <div className="px-3 py-2">
              <div className="h-2.5 w-12 animate-pulse rounded bg-muted" />
              <div className="mt-1.5 h-3 w-14 animate-pulse rounded bg-muted" />
            </div>
            <div className="px-3 py-2">
              <div className="h-2.5 w-12 animate-pulse rounded bg-muted" />
              <div className="mt-1.5 h-3 w-14 animate-pulse rounded bg-muted" />
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export function PostgresSpecificationCard({
  projectId,
  databaseId,
}: PostgresSpecificationCardProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const { canWrite: canUpgrade, writeTooltip: upgradeDisabledTooltip } =
    useDatabaseAdminOperationsAccess()
  const { database, isLoading: databaseLoading } = usePostgresDatabase(
    projectId,
    databaseId,
  )
  const { data: specificationsData, isLoading: specificationsLoading } =
  useDatabaseSpecifications(projectId, POSTGRES_DATABASE_SPECS_SOURCE)

  const specs = useMemo(
    () =>
      mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )

  const currentSpec = useMemo(
    () => specs.find((spec) => spec.id === database?.specification),
    [specs, database?.specification],
  )

  const nextEnabledSpec = useMemo(
    () => getNextEnabledSpec(specs, database?.specification),
    [specs, database?.specification],
  )
  const nextLockedSpec = useMemo(
    () => getNextLockedSpec(specs, database?.specification),
    [specs, database?.specification],
  )

  const billingEnabled = getActiveProfileFeatures().billing
  const nav = postgresNav({ projectId, databaseId })

  const specLabel =
    currentSpec?.label ?? (coerceTrimmedString(database?.specification) || 'Compute tier')
  const cpuLabel =
    currentSpec?.cpu ??
    (database?.cpu ? formatDedicatedSpecCpu(database.cpu) : '-')
  const memoryLabel =
    currentSpec?.memory ??
    (database?.memory ? formatDedicatedSpecMemory(database.memory) : '-')
  const storageLabel =
    database?.storage && database.storage > 0
      ? formatDedicatedSpecStorage(database.storage)
      : (currentSpec?.storage ?? '-')

  const isLoading =
    (databaseLoading && !database) ||
    (specificationsLoading && specs.length === 0)

  const showComputeUpgrade =
    canUpgrade && (!!nextEnabledSpec || !!nextLockedSpec)
  const showPlanUpgrade =
    !showComputeUpgrade && !!nextLockedSpec && billingEnabled

  if (isLoading) {
    return <SpecCardSkeleton />
  }

  if (!database) return null

  const upgradeAction = showComputeUpgrade ? (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="h-7 w-full rounded-none text-[11px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
    >
      <Link {...nav.computeSettings()}>{t('Upgrade compute')}</Link>
    </Button>
  ) : showPlanUpgrade ? (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="h-7 w-full rounded-none text-[11px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
    >
      {billingEnabled && project?.teamId ? (
        <Link to="/upgrade" search={{ orgId: project.teamId }}>
          {t('Upgrade plan')}
        </Link>
      ) : billingEnabled ? (
        <Link to="/upgrade">{t('Upgrade plan')}</Link>
      ) : (
        <span>{t('Upgrade plan')}</span>
      )}
    </Button>
  ) : !canUpgrade && nextEnabledSpec ? (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="block w-full">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-full rounded-none text-[11px] font-medium"
              disabled
            >
              {t('Upgrade compute')}
            </Button>
          </span>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-xs">
          <p className="text-[13px]">
            {upgradeDisabledTooltip ??
              t("You don't have permission to change database settings.")}
          </p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ) : null

  return (
    <div className="shrink-0 border-t border-border bg-background px-2.5 py-2">
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-3 py-2.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Compute tier')}
          </p>
          <p className="mt-0.5 truncate text-[13px] font-semibold leading-tight text-foreground">
            {specLabel}
          </p>
        </div>

        <div className="border-t border-border">
          <div className="grid grid-cols-3 divide-x divide-border">
            <SpecMetric label="CPU" value={cpuLabel} />
            <SpecMetric label={t('Memory')} value={memoryLabel} />
            <SpecMetric label={t('Storage')} value={storageLabel} />
          </div>
        </div>

        {upgradeAction ? (
          <div className="border-t border-border bg-muted/20">{upgradeAction}</div>
        ) : null}
      </div>
    </div>
  )
}
