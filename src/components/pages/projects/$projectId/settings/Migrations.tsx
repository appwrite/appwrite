import { useState, useEffect } from 'react'
import { useProjectMigrations, useProject } from '@/lib/react-query/hooks'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { Loader2, ArrowRightLeft } from 'lucide-react'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import { MigrationDetailsDrawer } from './migrations/MigrationDetailsDrawer'
import { MigrationContextMenu } from './migrations/MigrationContextMenu'
import {
  getMigrationProgress,
  getMigrationCounts,
} from './migrations/migrationProgress'

export interface MigrationsInitialData {
  migrations: Models.Migration[]
  total: number
}

interface MigrationsProps {
  projectId: string
  /** Prefetched data from route loader; used for first paint to avoid loading spinner */
  initialData?: MigrationsInitialData
}

export function Migrations({ projectId, initialData }: MigrationsProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const region = project?.region

  const [selectedMigrationId, setSelectedMigrationId] = useState<string | null>(
    null,
  )
  const { migrations, total, isLoading } = useProjectMigrations(
    projectId,
    region,
  )
  const detailsOpen = selectedMigrationId !== null
  // Use hook data when available; otherwise use prefetched initialData so first paint has content
  const effectiveMigrations =
    migrations.length > 0 ? migrations : (initialData?.migrations ?? [])
  const effectiveTotal =
    migrations.length > 0 ? total : (initialData?.total ?? 0)
  const selectedMigration =
    effectiveMigrations.find((m) => m.$id === selectedMigrationId) ?? null
  const showLoading = isLoading && !initialData

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<
      string,
      {
        label: string
        variant: 'success' | 'error' | 'warning' | 'processing'
        icon?: typeof Loader2
        spin?: boolean
      }
    > = {
      completed: { label: 'Complete', variant: 'success' },
      processing: {
        label: 'Processing',
        variant: 'processing',
        icon: Loader2,
        spin: true,
      },
      failed: { label: 'Failed', variant: 'error' },
      pending: { label: 'Pending', variant: 'warning' },
    }

    const config = statusConfig[status] || {
      label: 'Waiting',
      variant: 'warning' as const,
    }
    const Icon = config.icon

    return (
      <Badge variant={config.variant} className="text-[10px] shrink-0 gap-1.5">
        {Icon && (
          <Icon className={cn('h-3 w-3', config.spin && 'animate-spin')} />
        )}
        {t(config.label)}
      </Badge>
    )
  }

  const handleViewDetails = (migration: Models.Migration) => {
    setSelectedMigrationId(migration.$id)
  }

  const handleCloseDrawer = () => {
    setSelectedMigrationId(null)
  }

  // Close drawer if the selected migration is no longer in the list (e.g. deleted, realtime)
  useEffect(() => {
    if (
      selectedMigrationId &&
      !effectiveMigrations.some((m) => m.$id === selectedMigrationId)
    ) {
      setSelectedMigrationId(null)
    }
  }, [selectedMigrationId, effectiveMigrations])

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 space-y-4">
      {showLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : effectiveMigrations.length === 0 ? (
        <EmptyState
          icon={ArrowRightLeft}
          title={t('No migrations yet')}
          description={t('Import data from another platform or export your project data')}
          isEmpty={true}
          variant="card"
        />
      ) : (
        <div className="rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Migration ID')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Date')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Source')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Destination')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Status')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Progress')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {effectiveMigrations.map((migration) => {
                const showProgress =
                  migration.status === 'pending' ||
                  migration.status === 'processing'
                const progress = getMigrationProgress(migration)
                const { succeeded, total } = getMigrationCounts(migration)
                const hasCounts = total > 0
                return (
                  <MigrationContextMenu
                    key={migration.$id}
                    projectId={projectId}
                    region={region}
                    migration={migration}
                    onViewDetails={handleViewDetails}
                  >
                  <TableRow
                    className="cursor-pointer hover:bg-muted/50"
                    onClick={() => handleViewDetails(migration)}
                  >
                    <TableCell className="px-4 py-3">
                      <CopyableId id={migration.$id} size="xs" />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip
                        date={migration.$createdAt}
                        className="text-[12px] text-muted-foreground font-mono"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="text-[13px] text-muted-foreground">
                        {migration.source}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="text-[13px] text-muted-foreground">
                        {(migration as { destination?: string }).destination ??
                          '-'}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        {getStatusBadge(migration.status)}
                        {hasCounts && (
                          <span className="text-[11px] text-muted-foreground">
                            {succeeded} / {total}
                          </span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {showProgress ? (
                        <div className="min-w-[120px]">
                          <ProgressBarRow value={progress} className="mb-0" />
                        </div>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">
                           - 
                        </span>
                      )}
                    </TableCell>
                  </TableRow>
                  </MigrationContextMenu>
                )
              })}
            </TableBody>
          </Table>
        </div>
      )}

      {selectedMigrationId && selectedMigration && (
        <MigrationDetailsDrawer
          open={detailsOpen}
          onOpenChange={(open) => !open && handleCloseDrawer()}
          migration={selectedMigration}
        />
      )}
    </div>
  )
}
