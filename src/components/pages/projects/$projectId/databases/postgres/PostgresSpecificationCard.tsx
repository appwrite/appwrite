import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { canCreateDatabase } from '@/lib/console-access-checks'
import {
  formatDedicatedSpecCpu,
  formatDedicatedSpecMemory,
  mapDedicatedDatabaseSpecifications,
  type SpecOption,
} from '@/lib/database-specs'
import { postgresNav } from '@/lib/postgres-database-routes'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useDatabaseSpecifications,
  useOrganizationScopes,
  usePostgresDatabase,
  useProject,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { useMemo } from 'react'

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

export function PostgresSpecificationCard({
  projectId,
  databaseId,
}: PostgresSpecificationCardProps) {
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { database, isLoading: databaseLoading } = usePostgresDatabase(
    projectId,
    databaseId,
  )
  const { data: specificationsData, isLoading: specificationsLoading } =
    useDatabaseSpecifications(projectId)

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

  const canUpgrade = canCreateDatabase(access, features)
  const billingEnabled = getActiveProfileFeatures().billing
  const nav = postgresNav({ projectId, databaseId })

  const specLabel =
    currentSpec?.label ?? database?.specification?.trim() ?? 'Compute tier'
  const cpuLabel =
    currentSpec?.cpu ??
    (database?.cpu ? formatDedicatedSpecCpu(database.cpu) : '—')
  const memoryLabel =
    currentSpec?.memory ??
    (database?.memory ? formatDedicatedSpecMemory(database.memory) : '—')
  const storageLabel =
    database?.storage && database.storage > 0
      ? `${database.storage} GB`
      : '—'
  const connectionsLabel =
    currentSpec?.connections ??
    (database?.networkMaxConnections
      ? String(database.networkMaxConnections)
      : null)

  const isLoading =
    (databaseLoading && !database) ||
    (specificationsLoading && specs.length === 0)

  const showPlanUpgrade = !nextEnabledSpec && !!nextLockedSpec && billingEnabled
  const showComputeUpgrade = !!nextEnabledSpec && canUpgrade

  const specItems = [
    { label: 'CPU', value: cpuLabel },
    { label: 'Memory', value: memoryLabel },
    { label: 'Storage', value: storageLabel },
    ...(connectionsLabel
      ? [{ label: 'Connections', value: connectionsLabel }]
      : []),
  ]

  if (isLoading) {
    return (
      <div className="shrink-0 border-t border-border bg-background px-4 py-4">
        <div className="h-3 w-24 animate-pulse rounded bg-muted" />
        <div className="mt-2 h-4 w-32 animate-pulse rounded bg-muted" />
        <div className="mt-4 space-y-2.5">
          <div className="h-3.5 animate-pulse rounded bg-muted" />
          <div className="h-3.5 animate-pulse rounded bg-muted" />
          <div className="h-3.5 animate-pulse rounded bg-muted" />
          <div className="h-3.5 animate-pulse rounded bg-muted" />
        </div>
      </div>
    )
  }

  if (!database) return null

  const upgradeButton = showComputeUpgrade ? (
    <Button
      asChild
      variant="outline"
      size="sm"
      className="mt-4 h-8 w-full text-[13px]"
    >
      <Link {...nav.settings()}>Upgrade compute</Link>
    </Button>
  ) : showPlanUpgrade ? (
    <Button
      asChild
      variant="outline"
      size="sm"
      className="mt-4 h-8 w-full text-[13px]"
    >
      {billingEnabled && project?.teamId ? (
        <Link to="/upgrade" search={{ orgId: project.teamId }}>
          Upgrade plan
        </Link>
      ) : billingEnabled ? (
        <Link to="/upgrade">Upgrade plan</Link>
      ) : (
        <span>Upgrade plan</span>
      )}
    </Button>
  ) : !canUpgrade && nextEnabledSpec ? (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="mt-4 block w-full">
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-full text-[13px]"
              disabled
            >
              Upgrade compute
            </Button>
          </span>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-xs">
          <p className="text-[13px]">
            You don&apos;t have permission to change database settings.
          </p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ) : null

  return (
    <div className="shrink-0 border-t border-border bg-background px-4 py-4">
      <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        Specification
      </p>
      <p className="mt-1.5 truncate text-[14px] font-semibold text-foreground">
        {specLabel}
      </p>

      <dl className="mt-4 space-y-2.5">
        {specItems.map((item) => (
          <div
            key={item.label}
            className="flex items-baseline justify-between gap-4 text-[13px]"
          >
            <dt className="shrink-0 text-muted-foreground">{item.label}</dt>
            <dd className="min-w-0 truncate text-right font-medium tabular-nums text-foreground">
              {item.value}
            </dd>
          </div>
        ))}
      </dl>

      {upgradeButton}
    </div>
  )
}
