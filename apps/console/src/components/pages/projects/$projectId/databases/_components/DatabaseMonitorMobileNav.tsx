import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import { useMemo, useCallback } from 'react'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import { Button } from '@/components/ui/button'
import {
  useProjectDatabase,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'
import {
  getEffectiveDatabaseSpecIdForMonitoring,
  isServerlessDatabaseMonitoring,
} from '@/lib/database-specs'
import { useT } from '@/lib/i18n/translate'
import type { DatabaseRouteKind } from '@/lib/database-routes'

type DatabaseMonitorMobileNavProps = {
  projectId: string
  databaseId: string
  dbKind: DatabaseRouteKind
}

export function DatabaseMonitorMobileNav({
  projectId,
  databaseId,
  dbKind,
}: DatabaseMonitorMobileNavProps) {
  const t = useT()
  const { database } = useProjectDatabase(projectId, databaseId, dbKind)
  const { databases: dedicatedDatabases } = useProjectDedicatedDatabases(
    projectId,
  )
  const databaseType =
    (database as { databaseType?: ApiDatabaseType } | null)?.databaseType ??
    ApiDatabaseType.Tablesdb
  const dedicated = useMemo(
    () => dedicatedDatabases.find((item) => item.$id === databaseId),
    [dedicatedDatabases, databaseId],
  )
  // Same fallback as DatabaseMonitorView: product DBs with dedicated compute
  // may be missing from the engine list, so use the product model's spec too.
  const productSpecId =
    (database as { specification?: string | null } | null)?.specification ??
    null
  const specId = getEffectiveDatabaseSpecIdForMonitoring(
    databaseType,
    coerceTrimmedString(dedicated?.specification) || coerceTrimmedString(productSpecId) || null,
  )
  const serverless = isServerlessDatabaseMonitoring(databaseType, specId)

  const sections = useMemo(
    () =>
      serverless
        ? [
            { id: 'reads', label: 'Read operations' },
            { id: 'writes', label: 'Write operations' },
          ]
        : [
            { id: 'cpu', label: 'CPU' },
            { id: 'memory', label: 'Memory' },
            { id: 'storage', label: 'Storage' },
            { id: 'connections', label: 'Connections' },
            { id: 'qps', label: 'Queries per second' },
            { id: 'iops', label: 'Disk IOPS' },
          ],
    [serverless],
  )

  const scrollToChart = useCallback((id: string) => {
    document.getElementById(`monitor-chart-${id}`)?.scrollIntoView({
      behavior: 'smooth',
      block: 'start',
    })
  }, [])

  return (
    <div
      className="-mx-1 flex gap-1 overflow-x-auto pb-0.5"
      style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
    >
      {sections.map((s) => (
        <Button
          key={s.id}
          type="button"
          variant="outline"
          size="sm"
          className="h-8 shrink-0 whitespace-nowrap text-[12px]"
          onClick={() => scrollToChart(s.id)}
        >
          {t(s.label)}
        </Button>
      ))}
    </div>
  )
}
