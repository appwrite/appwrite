import { useMemo } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
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
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import { ServerlessSpecPrice } from '../_components/ServerlessSpecPrice'
import { DedicatedDatabaseRegionUnavailableBadge } from '../_components/DedicatedDatabaseRegionUnavailableCard'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import {
  formatDedicatedDatabaseRegionUnavailableDescription,
  projectSupportsDedicatedDatabaseCompute,
} from '@/lib/databases/dedicated-database-regions'
import { planSupportsDedicatedDatabases } from '@/lib/databases/dedicated-database-plan'
import {
  SERVERLESS_DATABASE_SPEC_ID,
  TABLE_DB_SPEC_OPTIONS,
  hasEnabledDedicatedComputeOptions,
  hasLockedDatabaseSpecifications,
  isServerlessDatabaseSpecId,
  mapDedicatedDatabaseSpecifications,
  type SpecOption,
} from '@/lib/database-specs'
import { dedicatedDatabaseSourceFromRouteKind } from '@/lib/databases/dedicated-database-source'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import {
  invalidateDatabaseModel,
  refetchProjectDatabaseLists,
  seedDatabaseProductRouteKind,
  updateProductDatabaseSpecification,
  useDatabaseSpecifications,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import type { ProjectDatabaseDetail } from './types'

const SERVERLESS_SPEC_OPTION =
  TABLE_DB_SPEC_OPTIONS.find((spec) => spec.id === SERVERLESS_DATABASE_SPEC_ID) ??
  TABLE_DB_SPEC_OPTIONS[0]

type DatabaseSpecificationCardProps = {
  projectId: string
  databaseId: string
  dbKind: DatabaseRouteKind
  database: ProjectDatabaseDetail
  canWrite: boolean
}

function resolveCurrentSpecId(
  dbKind: DatabaseRouteKind,
  specification: string | null | undefined,
): string {
  const trimmed = coerceTrimmedString(specification)
  if (trimmed && !isServerlessDatabaseSpecId(trimmed)) return trimmed
  if (dbKind === 'tablesdb') return SERVERLESS_DATABASE_SPEC_ID
  return trimmed || ''
}

export function DatabaseSpecificationCard({
  projectId,
  databaseId,
  dbKind,
  database,
  canWrite,
}: DatabaseSpecificationCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const regionSupportsDedicatedCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)
  const planSupportsDedicatedCompute =
    planSupportsDedicatedDatabases(organizationPlan)

  const specsSource = dedicatedDatabaseSourceFromRouteKind(dbKind)
  const { data: specificationsData, isSuccess: specificationsLoaded } =
    useDatabaseSpecifications(projectId, specsSource)

  const apiSpecs = useMemo(
    () =>
      mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )

  const currentSpecId = resolveCurrentSpecId(dbKind, database.specification)
  const currentIsServerless = isServerlessDatabaseSpecId(currentSpecId)

  const specs = useMemo((): SpecOption[] => {
    if (dbKind !== 'tablesdb') return apiSpecs
    return [SERVERLESS_SPEC_OPTION, ...apiSpecs]
  }, [apiSpecs, dbKind])

  const currentSpecIndex = useMemo(
    () =>
      specs.findIndex((spec) => {
        if (currentIsServerless) {
          return isServerlessDatabaseSpecId(spec.id)
        }
        return spec.id === currentSpecId
      }),
    [currentIsServerless, currentSpecId, specs],
  )

  const upgradeMutation = useMutation({
    mutationFn: (specification: string) =>
      updateProductDatabaseSpecification(
        projectId,
        databaseId,
        dbKind,
        specification,
        database.specification,
        database.name,
      ),
    onSuccess: async () => {
      seedDatabaseProductRouteKind(projectId, databaseId, dbKind)
      invalidateDatabaseModel(projectId, databaseId)
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['database', 'project', projectId, databaseId],
        }),
        refetchProjectDatabaseLists(queryClient, projectId),
      ])
      toast.success(
        currentIsServerless
          ? t('Migration to dedicated compute started')
          : t('Compute tier update started'),
      )
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update compute tier')))
    },
  })

  if (!regionSupportsDedicatedCompute) {
    return (
      <div
        data-card-id="specification"
        className="rounded-xl border border-border bg-card/50 overflow-hidden opacity-80"
      >
        <div className="px-6 py-4">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Specification')}
            </h3>
            <DedicatedDatabaseRegionUnavailableBadge />
          </div>
          <p className="text-[13px] text-muted-foreground mt-2">
            {formatDedicatedDatabaseRegionUnavailableDescription(t)}
          </p>
        </div>
      </div>
    )
  }

  if (planSupportsDedicatedCompute === false) {
    return (
      <div
        data-card-id="specification"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Specification')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Dedicated compute is not available on your current plan.')}{' '}
            <UpgradePlanLink orgId={project?.teamId} />{' '}
            {t('to unlock dedicated databases.')}
          </p>
        </div>
      </div>
    )
  }

  if (
    dbKind !== 'tablesdb' &&
    specificationsLoaded &&
    !hasEnabledDedicatedComputeOptions(apiSpecs)
  ) {
    return (
      <div
        data-card-id="specification"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Specification')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Not available on your current plan.')}{' '}
            <UpgradePlanLink orgId={project?.teamId} />{' '}
            {t('to unlock this database type.')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div
      data-card-id="specification"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Specification')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {currentIsServerless
            ? t(
                'Upgrade from serverless to a dedicated tier to reserve CPU, memory, and connection limits. Migrating applies with a brief read-only window during cutover.',
              )
            : t(
                'Change the compute tier for this database. Upgrades apply with zero downtime via rolling cutover.',
              )}
        </p>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border bg-muted/40">
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Tier')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              CPU
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Memory')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
              {t('Connections')}
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
              {t('Price')}
            </TableHead>
            <TableHead className="px-6 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[120px]" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {specs.map((spec, index) => {
            const isCurrent = currentIsServerless
              ? isServerlessDatabaseSpecId(spec.id)
              : spec.id === currentSpecId
            const locked = spec.comingSoon === true
            const isDowngrade =
              currentSpecIndex >= 0 && index < currentSpecIndex
            // TablesDB serverless → dedicated: createMigration.
            // Dedicated product DBs: product update({ specification }).
            const canUpgrade =
              canWrite &&
              !locked &&
              !isCurrent &&
              !isDowngrade &&
              !isServerlessDatabaseSpecId(spec.id) &&
              (currentSpecIndex < 0 || index > currentSpecIndex) &&
              (dbKind === 'tablesdb' ||
                dbKind === 'documentsdb' ||
                dbKind === 'vectorsdb')

            return (
              <TableRow
                key={spec.id}
                className={cn(
                  'border-b border-border last:border-b-0',
                  isCurrent && 'bg-primary/5',
                )}
              >
                <TableCell className="px-6 py-3">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[13px] font-medium text-foreground">
                      {isServerlessDatabaseSpecId(spec.id)
                        ? t(spec.label)
                        : spec.label}
                    </span>
                    {isCurrent ? (
                      <Badge
                        variant="success"
                        className="text-[10px] shrink-0"
                      >
                        {t('Current')}
                      </Badge>
                    ) : null}
                    {locked ? (
                      <Badge
                        variant="inactive"
                        className="text-[10px] shrink-0"
                      >
                        {t('Coming soon')}
                      </Badge>
                    ) : null}
                  </span>
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                  {spec.cpu}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                  {spec.memory}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] tabular-nums text-muted-foreground">
                  {spec.connections}
                </TableCell>
                <TableCell className="px-4 py-3 text-end text-[13px] font-medium tabular-nums text-foreground">
                  {isServerlessDatabaseSpecId(spec.id) ? (
                    <ServerlessSpecPrice plan={organizationPlan} />
                  ) : (
                    spec.price
                  )}
                </TableCell>
                <TableCell className="px-6 py-3 text-end">
                  {canUpgrade ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      disabled={upgradeMutation.isPending}
                      onClick={() => upgradeMutation.mutate(spec.id)}
                    >
                      {t('Upgrade')}
                    </Button>
                  ) : null}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
      {hasLockedDatabaseSpecifications(specs) ? (
        <div className="px-6 py-3">
          <SpecificationsUpgradeNote
            orgId={project?.teamId}
            showContactSales
          />
        </div>
      ) : null}
    </div>
  )
}
