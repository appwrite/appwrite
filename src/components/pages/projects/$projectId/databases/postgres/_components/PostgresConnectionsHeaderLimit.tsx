import { useMemo } from 'react'
import {
  mapDedicatedDatabaseSpecifications,
  parseDatabaseMaxConnections,
} from '@/lib/database-specs'
import { isPostgresClientBackend } from '@/lib/postgres-metrics'
import {
  useDatabaseSpecifications,
  usePostgresActiveConnections,
  usePostgresDatabase,
} from '@/lib/react-query/hooks'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

type PostgresConnectionsHeaderLimitProps = {
  projectId: string
  databaseId: string
}

function getConnectionUsageBadgeVariant(
  percentage: number | null,
): 'info' | 'warning' | 'error' {
  if (percentage == null) return 'info'
  if (percentage >= 90) return 'error'
  if (percentage >= 75) return 'warning'
  return 'info'
}

export function PostgresConnectionsHeaderLimit({
  projectId,
  databaseId,
}: PostgresConnectionsHeaderLimitProps) {
  const { database } = usePostgresDatabase(projectId, databaseId)
  const { data: specificationsData } = useDatabaseSpecifications(projectId)
  const { connections, isLoading } = usePostgresActiveConnections(
    projectId,
    databaseId,
  )

  const currentSpec = useMemo(() => {
    const specs = mapDedicatedDatabaseSpecifications(
      specificationsData?.specifications,
    )
    return specs.find((spec) => spec.id === database?.specification)
  }, [database?.specification, specificationsData?.specifications])

  const maxConnections = useMemo(
    () => parseDatabaseMaxConnections(currentSpec?.connections),
    [currentSpec?.connections],
  )

  const clientConnectionCount = useMemo(
    () => connections.filter(isPostgresClientBackend).length,
    [connections],
  )

  const usagePercent = useMemo(() => {
    if (maxConnections == null) return null
    return (clientConnectionCount / maxConnections) * 100
  }, [clientConnectionCount, maxConnections])

  const connectionsLimitLabel = currentSpec?.connections?.trim()
  const isSharedLimit = connectionsLimitLabel === 'Shared'

  if (!connectionsLimitLabel || connectionsLimitLabel === '—') {
    return null
  }

  const badgeVariant = getConnectionUsageBadgeVariant(usagePercent)
  const badgeLabel =
    maxConnections != null
      ? isLoading
        ? `— / ${maxConnections}`
        : `${clientConnectionCount} / ${maxConnections}`
      : isSharedLimit
        ? 'Shared limit'
        : connectionsLimitLabel

  const tooltipText =
    maxConnections != null
      ? isLoading
        ? `This compute tier allows up to ${maxConnections} client connections.`
        : `${clientConnectionCount} of ${maxConnections} client connections are in use on this tier.`
      : isSharedLimit
        ? 'This database uses a shared connection pool. There is no fixed per-instance limit.'
        : `Connection limit for this compute tier: ${connectionsLimitLabel}.`

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Badge
            variant={badgeVariant}
            className="shrink-0 text-[10px] tabular-nums"
          >
            {badgeLabel}
            {maxConnections != null ? ' connections' : null}
          </Badge>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="max-w-xs text-[12px]">
          <p>{tooltipText}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
