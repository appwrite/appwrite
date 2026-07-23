import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  useProjectProductDatabases,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import {
  databaseRouteKindFromApiType,
  dbNavLink,
} from '@/lib/database-routes'
import { AlertCircle, Braces, CheckCircle2, Layers, Loader2 } from 'lucide-react'
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
  ResourceCard,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '../../shared/ResourceCard'
import { DatabaseContextMenu } from './DatabaseContextMenu'
import {
  DedicatedDatabaseRegionUnavailableBadge,
  DedicatedDatabaseRegionUnavailableCard,
} from './DedicatedDatabaseRegionUnavailableCard'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canShowDatabaseSecuritySettings } from '@/lib/console-access-checks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'

type ProductDatabasesSectionProps = {
  projectId: string
  backend: ApiDatabaseType.Documentsdb | ApiDatabaseType.Vectorsdb
  title: string
  description: string
  viewMode: 'list' | 'grid'
  regionSupported?: boolean
}

function sectionIcon(backend: ApiDatabaseType.Documentsdb | ApiDatabaseType.Vectorsdb) {
  if (backend === ApiDatabaseType.Documentsdb) {
    return Braces
  }
  return Layers
}

function databaseDeepLink(
  projectId: string,
  databaseId: string,
  apiType: ApiDatabaseType | undefined,
) {
  const dbKind = databaseRouteKindFromApiType(apiType)
  return dbNavLink(dbKind).dataGrid({
    projectId,
    dbKind,
    databaseId,
    resourceId: '-',
  })
}

export function ProductDatabasesSection({
  projectId,
  backend,
  title,
  description,
  viewMode,
  regionSupported = true,
}: ProductDatabasesSectionProps) {
  const t = useT()
  const Icon = sectionIcon(backend)

  if (!regionSupported) {
    return (
      <section className="mt-10">
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <h2 className="text-[15px] font-semibold text-foreground">{title}</h2>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('Beta')}
          </Badge>
          <DedicatedDatabaseRegionUnavailableBadge />
          <p className="w-full text-[13px] text-muted-foreground">{description}</p>
        </div>
        <DedicatedDatabaseRegionUnavailableCard icon={Icon} />
      </section>
    )
  }

  return (
    <ProductDatabasesSectionContent
      projectId={projectId}
      backend={backend}
      title={title}
      description={description}
      viewMode={viewMode}
    />
  )
}

function ProductDatabasesSectionContent({
  projectId,
  backend,
  title,
  description,
  viewMode,
}: Omit<ProductDatabasesSectionProps, 'regionSupported'>) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showDbSecuritySettings = canShowDatabaseSecuritySettings(
    access,
    features,
  )
  const Icon = sectionIcon(backend)

  const {
    databases,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useProjectProductDatabases(
    projectId,
    backend,
    0,
    GRID_DEFAULT_PAGE_SIZE,
  )

  const errorMessage = error ? getErrorMessage(error) : null
  const loadingMessage =
    backend === ApiDatabaseType.Documentsdb
      ? t('Loading DocumentsDB…')
      : backend === ApiDatabaseType.Vectorsdb
        ? t('Loading VectorsDB…')
        : `${t('Loading')} ${title}…`
  const failedToLoadMessage =
    backend === ApiDatabaseType.Documentsdb
      ? t('Failed to load DocumentsDB')
      : backend === ApiDatabaseType.Vectorsdb
        ? t('Failed to load VectorsDB')
        : `${t('Failed to load')} ${title}`

  return (
    <section className="mt-10">
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <h2 className="text-[15px] font-semibold text-foreground">{title}</h2>
        <Badge variant="info" className="text-[10px] shrink-0">
          {t('Beta')}
        </Badge>
        <p className="w-full text-[13px] text-muted-foreground">{description}</p>
      </div>

      {isLoading ? (
        <div className="rounded-lg border border-border bg-card py-10 text-center">
          <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
          <p className="mt-3 text-[13px] text-muted-foreground">
            {loadingMessage}
          </p>
        </div>
      ) : errorMessage && databases.length === 0 ? (
        <div className="rounded-lg border border-destructive/30 bg-card py-10 px-6 text-center">
          <AlertCircle className="mx-auto h-9 w-9 text-destructive" />
          <h3 className="mt-4 text-[15px] font-semibold text-foreground">
            {failedToLoadMessage}
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
                <AlertTitle>
                {t("Couldn't refresh databases")}
              </AlertTitle>
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
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                      {t('Status')}
                    </TableHead>
                    {features.databaseBackups && (
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                        {t('Backups')}
                      </TableHead>
                    )}
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Created')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                      {t('Updated')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {databases.map((db) => (
                    <TableRow
                      key={db.$id}
                      className="cursor-pointer border-b border-border/50 hover:bg-muted/30"
                    >
                      <TableCell className="px-4 py-3">
                        <Link
                          {...databaseDeepLink(
                            projectId,
                            db.$id,
                            db.databaseType ?? backend,
                          )}
                          className="block min-w-0 group"
                        >
                          <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                            {db.name}
                          </p>
                          <div className="mt-0.5">
                            <CopyableId id={db.$id} size="xs" />
                          </div>
                        </Link>
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
                      {features.databaseBackups && (
                        <TableCell className="px-4 py-3">
                          <div className="flex items-center justify-center">
                            {db.hasBackupPolicy ? (
                              <Badge
                                variant="success"
                                className="gap-1.5 text-[11px] font-medium border px-2 py-0.5"
                              >
                                <CheckCircle2 className="h-3 w-3" />
                                {db.backupPolicyCount && db.backupPolicyCount > 0
                                  ? `${db.backupPolicyCount} ${db.backupPolicyCount === 1 ? t('policy') : t('policies')}`
                                  : (db.backupPolicy as { name?: string } | null)
                                      ?.name || t('Enabled')}
                              </Badge>
                            ) : (
                              <Badge
                                variant="warning"
                                className="gap-1.5 text-[11px] font-medium border px-2 py-0.5"
                              >
                                <AlertCircle className="h-3 w-3" />
                                {t('None')}
                              </Badge>
                            )}
                          </div>
                        </TableCell>
                      )}
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
            icon={Icon}
            title={t('No product databases yet')}
            description={t(
              'Create a database from the create database wizard.',
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
              <AlertTitle>
                {t("Couldn't refresh databases")}
              </AlertTitle>
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
            {databases.map((db) => (
              <DatabaseContextMenu
                key={db.$id}
                projectId={projectId}
                database={{
                  $id: db.$id,
                  name: db.name,
                  databaseType: db.databaseType ?? backend,
                }}
                showSecuritySettings={showDbSecuritySettings}
                showMonitor={features.usageStats}
                showBackups={features.databaseBackups}
              >
                <Link
                  {...databaseDeepLink(
                    projectId,
                    db.$id,
                    db.databaseType ?? backend,
                  )}
                >
                  <ResourceCard
                    title={db.name}
                    resourceId={db.$id}
                    icon={Icon}
                    iconColor="bg-muted text-muted-foreground"
                    status={db.enabled === false ? 'error' : undefined}
                    statusLabel={db.enabled === false ? t('Disabled') : undefined}
                    metadata={
                      features.databaseBackups
                        ? [
                            {
                              label: '',
                              value: db.hasBackupPolicy ? (
                                <Badge
                                  variant="success"
                                  className="gap-1.5 text-[11px] font-medium"
                                >
                                  {db.backupPolicyCount && db.backupPolicyCount > 0
                                    ? `${db.backupPolicyCount} ${db.backupPolicyCount === 1 ? 'policy' : 'policies'}`
                                    : (db.backupPolicy as { name?: string } | null)
                                        ?.name || 'Backup enabled'}
                                </Badge>
                              ) : (
                                <Badge
                                  variant="warning"
                                  className="gap-1.5 text-[11px] font-medium"
                                >
                                  {t('No backup policies')}
                                </Badge>
                              ),
                            },
                          ]
                        : []
                    }
                  />
                </Link>
              </DatabaseContextMenu>
            ))}
            {databases.length === 0 ? (
              <div className="col-span-full">
                <EmptyState
                  icon={Icon}
                  title={`${t('No databases yet for')} ${title}`}
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
