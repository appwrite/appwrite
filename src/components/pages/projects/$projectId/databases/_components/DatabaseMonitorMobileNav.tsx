import { useMemo, useCallback } from 'react'
import { DatabaseType as ApiDatabaseType } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { useProjectDatabase } from '@/lib/react-query/hooks'
import {
  getEffectiveDatabaseSpecIdForMonitoring,
  isServerlessDatabaseMonitoring,
} from '@/lib/database-specs'
import { useT } from '@/lib/i18n/translate'

type DatabaseMonitorMobileNavProps = {
  projectId: string
  databaseId: string
}

export function DatabaseMonitorMobileNav({
  projectId,
  databaseId,
}: DatabaseMonitorMobileNavProps) {
  const t = useT()
  const { database } = useProjectDatabase(projectId, databaseId)
  const databaseType =
    (database as { databaseType?: ApiDatabaseType } | null)?.databaseType ??
    ApiDatabaseType.Tablesdb
  const specId = getEffectiveDatabaseSpecIdForMonitoring(databaseType)
  const serverless = isServerlessDatabaseMonitoring(databaseType, specId)

  const sections = useMemo(
    () =>
      serverless
        ? [
            { id: 'reads', label: 'Read operations' },
            { id: 'writes', label: 'Write operations' },
          ]
        : [
            { id: 'cpu', label: 'CPU usage' },
            { id: 'memory', label: 'Memory usage' },
            { id: 'disk', label: 'Disk usage' },
            { id: 'network', label: 'Network throughput' },
            { id: 'connections', label: 'Connections' },
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
