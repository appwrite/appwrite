import { useMemo } from 'react'
import {
  mapDedicatedDatabaseSpecifications,
  parseDatabaseMaxConnections,
} from '@/lib/database-specs'
import { isMysqlClientBackend } from '@/lib/mysql-metrics'
import {
  MYSQL_DATABASE_SPECS_SOURCE,
  useDatabaseSpecifications,
  useMysqlActiveConnections,
  useMysqlDatabase,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'

type MysqlConnectionsHeaderLimitProps = {
  projectId: string
  databaseId: string
}

function getConnectionUsageTone(
  percentage: number | null,
): 'normal' | 'warning' | 'critical' {
  if (percentage == null) return 'normal'
  if (percentage >= 90) return 'critical'
  if (percentage >= 75) return 'warning'
  return 'normal'
}

export function MysqlConnectionsHeaderLimit({
  projectId,
  databaseId,
}: MysqlConnectionsHeaderLimitProps) {
  const t = useT()
  const { database } = useMysqlDatabase(projectId, databaseId)
  const { data: specificationsData } = useDatabaseSpecifications(projectId, MYSQL_DATABASE_SPECS_SOURCE)
  const { connections, isLoading } = useMysqlActiveConnections(
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
    () => connections.filter(isMysqlClientBackend).length,
    [connections],
  )

  const usagePercent = useMemo(() => {
    if (maxConnections == null) return null
    return (clientConnectionCount / maxConnections) * 100
  }, [clientConnectionCount, maxConnections])

  const connectionsLimitLabel =
    typeof currentSpec?.connections === 'string'
      ? currentSpec.connections.trim()
      : currentSpec?.connections != null
        ? String(currentSpec.connections).trim()
        : ''
  const isServerlessLimit = connectionsLimitLabel === 'Serverless'

  if (!connectionsLimitLabel || connectionsLimitLabel === '-') {
    return null
  }

  const usageTone = getConnectionUsageTone(usagePercent)

  const tooltipText =
    maxConnections != null
      ? isLoading
        ? `This compute tier allows up to ${maxConnections} client connections.`
        : `${clientConnectionCount} of ${maxConnections} client connections are in use on this tier.`
      : isServerlessLimit
        ? t('This database uses a serverless connection pool. There is no fixed per-instance limit.')
        : `Connection limit for this compute tier: ${connectionsLimitLabel}.`

  const inlineLabel =
    maxConnections != null
      ? isLoading
        ? `- / ${maxConnections.toLocaleString()}`
        : `${clientConnectionCount.toLocaleString()} / ${maxConnections.toLocaleString()}`
      : isServerlessLimit
        ? isLoading
          ? '-'
          : clientConnectionCount.toLocaleString()
        : connectionsLimitLabel

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className={cn(
              'shrink-0 text-[13px] font-normal tabular-nums',
              usageTone === 'critical' && 'text-red-500',
              usageTone === 'warning' && 'text-amber-600 dark:text-amber-500',
              usageTone === 'normal' && 'text-muted-foreground',
            )}
          >
            {inlineLabel}
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="max-w-xs text-[12px]">
          <p>{tooltipText}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
