import { useEffect, useMemo, useState } from 'react'
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
import { useNavigate } from '@tanstack/react-router'
import { AlertCircle, Loader2 } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import { MigrationDetailsDrawer } from './migrations/MigrationDetailsDrawer'
import { MigrationContextMenu } from './migrations/MigrationContextMenu'
import { MigrationsEmptyState } from './_components/MigrationsEmptyState'
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
  onFirstRunEmptyChange?: (empty: boolean) => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}

export function Migrations({
  projectId,
  initialData,
  onFirstRunEmptyChange,
  createDisabled = false,
  createDisabledTooltip,
}: MigrationsProps) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const region = project?.region

  const [selectedMigrationId, setSelectedMigrationId] = useState<string | null>(
    null,
  )
  const { migrations, isLoading, hasData, error, refetch } =
    useProjectMigrations(projectId, region)
  const detailsOpen = selectedMigrationId !== null
  // The list as last read, empty included, and kept through a refetch that
  // fails; until it is first read, the prefetched initialData, so first paint
  // has content
  const effectiveMigrations = useMemo(
    () => (hasData ? migrations : (initialData?.migrations ?? [])),
    [hasData, migrations, initialData],
  )
  const selectedMigration =
    effectiveMigrations.find((m) => m.$id === selectedMigrationId) ?? null
  const showLoading = isLoading && !initialData
  // A list that could not be read is not an empty one. Showing the first-run
  // screen for it tells someone looking for a migration that there is none, so
  // with nothing to show the failure takes the list's place. Rows already shown
  // stay, under a notice that they may be out of date.
  const loadFailed = !!error && effectiveMigrations.length === 0
  const refreshFailed = !!error && effectiveMigrations.length > 0
  const showsFirstRunEmptyState =
    !showLoading && !loadFailed && effectiveMigrations.length === 0
  useEffect(() => {
    onFirstRunEmptyChange?.(showsFirstRunEmptyState)
    return () => onFirstRunEmptyChange?.(false)
  }, [onFirstRunEmptyChange, showsFirstRunEmptyState])

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
      ) : loadFailed ? (
        <MigrationsReadError
          testId="migrations-load-failed"
          title={t('Failed to load migrations')}
          detail={getErrorMessage(error, t('Please try again.'))}
          onRetry={() => void refetch()}
        />
      ) : showsFirstRunEmptyState ? (
        <MigrationsEmptyState
          onImport={() =>
            navigate({
              to: '/projects/$projectId/settings/migrations/import',
              params: { projectId },
            })
          }
          importDisabled={createDisabled}
          importDisabledTooltip={createDisabledTooltip}
        />
      ) : (
        <>
          {refreshFailed ? (
            <MigrationsReadError
              testId="migrations-refresh-failed"
              title={t('Failed to refresh migrations')}
              detail={`${t('The list below may be out of date.')} ${getErrorMessage(error, t('Please try again.'))}`}
              onRetry={() => void refetch()}
            />
          ) : null}
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
                            {(migration as { destination?: string })
                              .destination ?? '-'}
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
                              <ProgressBarRow
                                value={progress}
                                className="mb-0"
                              />
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
        </>
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

/** The list could not be read: what failed, and a way to read it again. */
function MigrationsReadError({
  testId,
  title,
  detail,
  onRetry,
}: {
  testId: string
  title: string
  detail: string
  onRetry: () => void
}) {
  const t = useT()
  return (
    <Alert variant="destructive" data-testid={testId}>
      <AlertCircle className="h-4 w-4" />
      <AlertTitle>{title}</AlertTitle>
      <AlertDescription className="text-[13px]">
        <p>{detail}</p>
        <Button
          variant="outline"
          size="sm"
          className="mt-3 h-8 text-[13px]"
          onClick={onRetry}
        >
          {t('Retry')}
        </Button>
      </AlertDescription>
    </Alert>
  )
}
