import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  useProjectConsoleDatabases,
  useProjectDedicatedDatabases,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import {
  DatabaseType as ApiDatabaseType,
  type Models,
} from '@appwrite.io/console'
import type { Database as DatabaseListItem } from '@/lib/utils/mock-data'
import {
  dedicatedDatabaseHomeLink,
  productDatabaseListLink,
} from '@/lib/database-routes'
import { AlertCircle, Database, Loader2 } from 'lucide-react'
import { useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
} from '../../shared/ResourceCard'
import {
  DatabaseClusterPreview,
  clusterNodeStatusesFromDatabaseStatus,
  mockDatabaseConnections,
} from './DatabaseClusterPreview'
import { DatabaseContextMenu } from './DatabaseContextMenu'
import { DatabaseOperationsChartPreview } from './DatabaseOperationsChartPreview'
import { NoBackupPoliciesWarningIcon } from './DatabaseBackupsNavLink'
import { DatabaseTypeBadge } from './DatabaseTypeIcon'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canShowDatabaseSecuritySettings } from '@/lib/console-access-checks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'

type DatabaseWithBackup = {
  $id: string
  name: string
  enabled?: boolean
  createdAt?: string
  updatedAt?: string
  hasBackupPolicy?: boolean
  backupPolicy?: { name?: string }
  backupPolicyCount?: number
  databaseType?: ApiDatabaseType
}

type AllDatabasesSectionProps = {
  projectId: string
  viewMode: 'list' | 'grid'
}

function databaseCardLink(
  projectId: string,
  db: { $id: string; databaseType?: ApiDatabaseType },
  dedicated?: Pick<Models.DedicatedDatabase, '$id' | 'api' | 'engine'> | null,
) {
  if (dedicated) {
    const dedicatedLink = dedicatedDatabaseHomeLink(projectId, dedicated)
    if (dedicatedLink) return dedicatedLink
  }
  return productDatabaseListLink(projectId, db.$id, db.databaseType)
}

export function AllDatabasesSection({
  projectId,
  viewMode,
}: AllDatabasesSectionProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showDbSecuritySettings = canShowDatabaseSecuritySettings(
    access,
    features,
  )

  const {
    databases,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useProjectConsoleDatabases(projectId, 0, GRID_DEFAULT_PAGE_SIZE)

  const { databases: dedicatedDatabases } =
    useProjectDedicatedDatabases(projectId)

  const dedicatedById = useMemo(() => {
    const map = new Map<string, Models.DedicatedDatabase>()
    for (const db of dedicatedDatabases) {
      map.set(db.$id, db)
    }
    return map
  }, [dedicatedDatabases])

  const errorMessage = error ? getErrorMessage(error) : null

  return (
    <section className="mb-10">
      {isLoading ? (
        <div className="rounded-lg border border-border bg-card py-10 text-center">
          <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
          <p className="mt-3 text-[13px] text-muted-foreground">
            {t('Loading databases...')}
          </p>
        </div>
      ) : errorMessage && databases.length === 0 ? (
        <div className="rounded-lg border border-destructive/30 bg-card py-10 px-6 text-center">
          <AlertCircle className="mx-auto h-9 w-9 text-destructive" />
          <h3 className="mt-4 text-[15px] font-semibold text-foreground">
            {t('Failed to load databases')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">{errorMessage}</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-6"
            onClick={() => void refetch()}
            disabled={isFetching}
          >
            {t('Try again')}
          </Button>
        </div>
      ) : viewMode === 'list' ? (
        databases.length > 0 ? (
          <>
            {errorMessage ? (
              <Alert variant="destructive" className="mb-4">
                <AlertCircle className="h-4 w-4" />
                <AlertTitle>{t("Couldn't refresh databases")}</AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <p className="text-[13px]">{errorMessage}</p>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0 border-destructive/40 bg-background"
                    onClick={() => void refetch()}
                    disabled={isFetching}
                  >
                    {t('Try again')}
                  </Button>
                </AlertDescription>
              </Alert>
            ) : null}
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Database')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      {t('Type')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                      {t('Status')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Created')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Updated')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {databases.map((db: DatabaseWithBackup & DatabaseListItem) => (
                    <TableRow
                      key={db.$id}
                      className="cursor-pointer border-b border-border/50 hover:bg-muted/30"
                    >
                      <TableCell className="px-4 py-3">
                        <Link
                          {...databaseCardLink(
                            projectId,
                            db,
                            dedicatedById.get(db.$id),
                          )}
                          className="block min-w-0 group"
                        >
                          <div className="flex min-w-0 items-center gap-1.5">
                            <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                              {db.name}
                            </p>
                            {features.databaseBackups &&
                            !db.hasBackupPolicy ? (
                              <NoBackupPoliciesWarningIcon />
                            ) : null}
                          </div>
                          <div className="mt-0.5">
                            <CopyableId id={db.$id} size="xs" />
                          </div>
                        </Link>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DatabaseTypeBadge
                          apiType={db.databaseType}
                          engine={dedicatedById.get(db.$id)?.engine}
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center justify-center">
                          {db.enabled === false ? (
                            <Badge
                              variant="error"
                              className="text-[11px] font-medium border px-2 py-0.5"
                            >
                              {t('Disabled')}
                            </Badge>
                          ) : (
                            <Badge
                              variant="success"
                              className="text-[11px] font-medium border px-2 py-0.5"
                            >
                              {t('Enabled')}
                            </Badge>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={new Date(db.createdAt || new Date())}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DateTooltip
                          date={
                            new Date(
                              db.updatedAt || db.createdAt || new Date(),
                            )
                          }
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </>
        ) : (
          <EmptyState
            icon={Database}
            title={t('No databases yet')}
            description={t(
              'Create this product database from the create database wizard.',
            )}
            isEmpty
            variant="card"
          />
        )
      ) : (
        <>
          {errorMessage ? (
            <Alert variant="destructive" className="mb-4">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t("Couldn't refresh databases")}</AlertTitle>
              <AlertDescription className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[13px]">{errorMessage}</p>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="shrink-0 border-destructive/40 bg-background"
                  onClick={() => void refetch()}
                  disabled={isFetching}
                >
                  {t('Try again')}
                </Button>
              </AlertDescription>
            </Alert>
          ) : null}
          <div className={cn(RESOURCE_CARD_GRID_CLASSNAME)}>
            {databases.map((db: DatabaseWithBackup & DatabaseListItem) => {
              const dedicated = dedicatedById.get(db.$id)
              const isDedicated = Boolean(dedicated)
              const replicaCount = dedicated?.replicas ?? 0
              const specification = dedicated?.specification
              const nodeStatuses = clusterNodeStatusesFromDatabaseStatus(
                dedicated?.status ?? (db.enabled === false ? 'failed' : 'ready'),
                replicaCount,
              )
              const connectionSeed = db.$id
                .split('')
                .reduce((sum, char) => sum + char.charCodeAt(0), 0)
              const cardLink = databaseCardLink(projectId, db, dedicated)
              return (
                <DatabaseContextMenu
                  key={db.$id}
                  projectId={projectId}
                  database={{
                    $id: db.$id,
                    name: db.name,
                    databaseType: db.databaseType,
                  }}
                  showSecuritySettings={showDbSecuritySettings}
                  showMonitor={features.usageStats}
                  showBackups={features.databaseBackups}
                >
                  <Link {...cardLink} className="block min-w-0">
                    <div
                      className={cn(
                        RESOURCE_CARD_PADDED_CLASSNAME,
                        RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                        'pb-0',
                      )}
                    >
                      <div className="min-w-0 overflow-hidden">
                          <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                            <h3 className="truncate text-[14px] font-medium text-foreground">
                              {db.name}
                            </h3>
                            {features.databaseBackups &&
                            !db.hasBackupPolicy ? (
                              <NoBackupPoliciesWarningIcon />
                            ) : null}
                            {db.enabled === false ? (
                              <Badge
                                variant="error"
                                className="text-[10px] font-medium shrink-0"
                              >
                                {t('Disabled')}
                              </Badge>
                            ) : null}
                          </div>
                          <div className="mt-1.5">
                            <CopyableId
                              id={db.$id}
                              size="xs"
                              maxWidth={120}
                            />
                          </div>
                        </div>

                      {isDedicated ? (
                        <DatabaseClusterPreview
                          replicaCount={replicaCount}
                          nodeStatuses={nodeStatuses}
                        />
                      ) : (
                        <DatabaseOperationsChartPreview databaseId={db.$id} />
                      )}

                      <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
                        <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 text-[12px] text-muted-foreground">
                          <DatabaseTypeBadge
                            apiType={db.databaseType}
                            engine={dedicated?.engine}
                          />
                          <span className="truncate">
                            <span className="text-muted-foreground/80">
                              {t('Tier')}
                            </span>{' '}
                            <span className="font-medium text-foreground">
                              {specification || t('Serverless')}
                            </span>
                          </span>
                          {isDedicated ? (
                            <span className="truncate">
                              <span className="text-muted-foreground/80">
                                {t('Connections')}
                              </span>{' '}
                              <span className="font-medium tabular-nums text-foreground">
                                {mockDatabaseConnections(connectionSeed)}
                              </span>
                            </span>
                          ) : null}
                        </div>
                      </div>
                    </div>
                  </Link>
                </DatabaseContextMenu>
              )
            })}
            {databases.length === 0 ? (
              <div className="col-span-full">
                <EmptyState
                  icon={Database}
                  title={t('No databases yet')}
                  description={t(
                    'Create this product database from the create database wizard.',
                  )}
                  isEmpty
                  variant="card"
                />
              </div>
            ) : null}
          </div>
        </>
      )}
    </section>
  )
}
