import { useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { canCreateDatabase } from '@/lib/console-access-checks'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { dbNavLink } from '@/lib/database-routes'
import {
  formatDatabaseSpecDisplayTooltip,
  getEffectiveDatabaseSpecIdForMonitoring,
  getSpecOptionById,
  isServerlessDatabaseMonitoring,
  mapDedicatedDatabaseSpecifications,
  resolveDatabaseSpecDisplayParts,
  type SpecOption,
} from '@/lib/database-specs'
import {
  hasDedicatedDatabaseCompute,
  resolveDatabaseComputeSpecId,
} from '@/lib/databases/database-compute'
import { coerceTrimmedString } from '@/lib/databases/dedicated-database-status'
import { planSupportsDedicatedDatabases } from '@/lib/databases/dedicated-database-plan'
import {
  projectSupportsDedicatedDatabaseCompute,
  formatDedicatedDatabaseRegionUnavailableDescription,
} from '@/lib/databases/dedicated-database-regions'
import { postgresNav } from '@/lib/postgres-database-routes'
import { mysqlNav } from '@/lib/mysql-database-routes'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  POSTGRES_DATABASE_SPECS_SOURCE,
  MYSQL_DATABASE_SPECS_SOURCE,
  dedicatedDatabaseSourceFromRouteKind,
  useDatabaseSpecifications,
  useOrganizationPlan,
  useOrganizationScopes,
  usePostgresDatabase,
  useMysqlDatabase,
  useProject,
  useProjectDatabase,
  useProjectDedicatedDatabases,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  DATABASE_SIDEBAR_FOOTER_STRIP_CLASS,
  DATABASE_SIDEBAR_FOOTER_STRIP_ROW_CLASS,
  DATABASE_SIDEBAR_STRIP_FULL_BLEED_CLASS,
} from './database-sidebar-chrome'
import { DatabaseSidebarComputeSpecDisplay } from './DatabaseSidebarComputeSpecDisplay'

type DatabaseSidebarComputeSpecProps = {
  projectId: string
  databaseId: string
  mode: 'product' | 'postgres' | 'mysql'
  dbKind?: DatabaseRouteKind
  /** Renders inside the nav footer with a spaced separator above. */
  variant?: 'footer' | 'standalone'
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

function isDedicatedDbFeatureEnabled(
  dbKind: DatabaseRouteKind | undefined,
  features: ReturnType<typeof useConsoleProfile>['features'],
): boolean {
  if (dbKind === 'documentsdb') return features.dedicatedDbsDocumentsDB
  if (dbKind === 'vectorsdb') return features.dedicatedDbsVectorsDB
  return features.dedicatedDbsSupport
}

export function DatabaseSidebarComputeSpec({
  projectId,
  databaseId,
  mode,
  dbKind,
  variant = 'standalone',
}: DatabaseSidebarComputeSpecProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { access } = useOrganizationScopes(project?.teamId)
  const specificationsSource =
    mode === 'postgres'
      ? POSTGRES_DATABASE_SPECS_SOURCE
      : mode === 'mysql'
        ? MYSQL_DATABASE_SPECS_SOURCE
        : dedicatedDatabaseSourceFromRouteKind(dbKind ?? 'tablesdb')
  const { data: specificationsData } = useDatabaseSpecifications(
    projectId,
    specificationsSource,
  )
  // Only fetch product DB metadata on product routes. Passing a native DB id into
  // `useProjectDatabase` probes tablesdb/documentsdb/vectorsdb and fails noisily.
  const { database: productDatabase } = useProjectDatabase(
    projectId,
    mode === 'product' ? databaseId : null,
    dbKind ?? 'tablesdb',
  )
  const { database: postgresDatabase } = usePostgresDatabase(
    projectId,
    mode === 'postgres' ? databaseId : null,
  )
  const { database: mysqlDatabase } = useMysqlDatabase(
    projectId,
    mode === 'mysql' ? databaseId : null,
  )
  const { databases: dedicatedDatabases } = useProjectDedicatedDatabases(
    projectId,
  )

  const specs = useMemo(
    () =>
      mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )

  const regionSupportsDedicatedCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)
  const planSupportsDedicatedCompute =
    planSupportsDedicatedDatabases(organizationPlan)
  const canManageCompute = canCreateDatabase(access, features)
  const billingEnabled = getActiveProfileFeatures().billing

  const dedicatedById = useMemo(() => {
    const map = new Map<string, (typeof dedicatedDatabases)[number]>()
    for (const dedicated of dedicatedDatabases) {
      map.set(dedicated.$id, dedicated)
    }
    return map
  }, [dedicatedDatabases])

  const resolved = useMemo(() => {
    const connectionsUnit = t('connections')

    if (mode === 'postgres' || mode === 'mysql') {
      const nativeDatabase =
        mode === 'postgres' ? postgresDatabase : mysqlDatabase
      const nativeDedicated = dedicatedById.get(databaseId)
      const specSlug =
        coerceTrimmedString(nativeDatabase?.specification) ||
        coerceTrimmedString(nativeDedicated?.specification) ||
        undefined
      const cpu = nativeDatabase?.cpu ?? nativeDedicated?.cpu ?? undefined
      const memory =
        nativeDatabase?.memory ?? nativeDedicated?.memory ?? undefined
      const displayParts = resolveDatabaseSpecDisplayParts(specs, specSlug, {
        cpuMillicores: cpu,
        memoryMb: memory,
        fallbackLabel: specSlug ?? t('Compute tier'),
      })
      const specTooltip =
        displayParts.variant === 'serverless'
          ? t('Serverless')
          : formatDatabaseSpecDisplayTooltip(displayParts, connectionsUnit) ??
            t('Compute tier')

      const computeLink =
        mode === 'postgres'
          ? postgresNav({ projectId, databaseId }).computeSettings()
          : mysqlNav({ projectId, databaseId }).computeSettings()

      return {
        displayParts,
        specTooltip,
        specSlug,
        computeLink,
        serverless: false,
      }
    }

    const databaseType =
      (productDatabase as { databaseType?: ApiDatabaseType } | null)
        ?.databaseType ?? ApiDatabaseType.Tablesdb
    const dedicated = dedicatedById.get(databaseId)
    const productHints = {
      databaseType:
        (productDatabase as { apiType?: string | null } | null)?.apiType ??
        databaseType,
      status:
        (productDatabase as { status?: string | null } | null)?.status ?? null,
      replicas:
        (productDatabase as { replicas?: number | null } | null)?.replicas ??
        null,
      specification:
        (productDatabase as { specification?: string | null } | null)
          ?.specification ?? null,
    }
    const resolvedSpecId = resolveDatabaseComputeSpecId(
      productHints,
      dedicated,
      {
        specs,
        rawSpecifications: specificationsData?.specifications ?? null,
      },
    )
    const dedicatedBacking = hasDedicatedDatabaseCompute(
      productHints,
      dedicated,
    )
    const serverless =
      !dedicatedBacking &&
      (resolvedSpecId == null ||
        isServerlessDatabaseMonitoring(databaseType, resolvedSpecId))
    const apiSpecId =
      resolvedSpecId &&
      !isServerlessDatabaseMonitoring(databaseType, resolvedSpecId)
        ? resolvedSpecId
        : dedicatedBacking
          ? null
          : getEffectiveDatabaseSpecIdForMonitoring(databaseType, null)
    const displayParts = resolveDatabaseSpecDisplayParts(specs, apiSpecId, {
      forceServerless: serverless,
      fallbackLabel:
        (apiSpecId ? getSpecOptionById(apiSpecId)?.label : undefined) ??
        (serverless ? t('Serverless') : apiSpecId || t('Dedicated')),
    })
    const specTooltip =
      displayParts.variant === 'serverless'
        ? t('Serverless')
        : formatDatabaseSpecDisplayTooltip(displayParts, connectionsUnit) ??
          (serverless ? t('Serverless') : apiSpecId || t('Dedicated'))

    const tableNavParams = {
      projectId,
      dbKind: dbKind ?? 'tablesdb',
      databaseId,
      resourceId: '-',
    }

    return {
      displayParts,
      specTooltip,
      specSlug: apiSpecId,
      serverless,
      computeLink: dbNavLink(tableNavParams.dbKind).dbSpecificationSettings(
        tableNavParams,
      ),
    }
  }, [
    dedicatedById,
    databaseId,
    dbKind,
    mode,
    postgresDatabase,
    mysqlDatabase,
    productDatabase,
    projectId,
    specificationsData?.specifications,
    specs,
    t,
  ])

  const nextEnabledSpec = useMemo(
    () => getNextEnabledSpec(specs, resolved.specSlug ?? undefined),
    [resolved.specSlug, specs],
  )
  const nextLockedSpec = useMemo(
    () => getNextLockedSpec(specs, resolved.specSlug ?? undefined),
    [resolved.specSlug, specs],
  )

  // Prefer compute/spec settings when available; /upgrade only as a fallback.
  const showUpgradeComingSoon =
    !regionSupportsDedicatedCompute &&
    (resolved.serverless || !!nextEnabledSpec || !!nextLockedSpec)
  const showComputeUpgrade =
    regionSupportsDedicatedCompute &&
    planSupportsDedicatedCompute === true &&
    canManageCompute &&
    (!!nextEnabledSpec || !!nextLockedSpec || resolved.serverless)
  const showPlanUpgrade =
    !showUpgradeComingSoon &&
    !showComputeUpgrade &&
    billingEnabled &&
    (planSupportsDedicatedCompute === false || !!nextLockedSpec)

  if (!isDedicatedDbFeatureEnabled(dbKind, features) && mode === 'product') {
    return null
  }

  if (mode === 'postgres' && !postgresDatabase && !dedicatedById.has(databaseId)) {
    return null
  }

  const upgradeLink = showComputeUpgrade ? (
    <Link
      {...resolved.computeLink}
      className="shrink-0 font-medium hover:text-foreground"
    >
      {t('Upgrade')}
    </Link>
  ) : showPlanUpgrade ? (
    <UpgradePlanLink orgId={project?.teamId} className="shrink-0 font-medium">
      {t('Upgrade')}
    </UpgradePlanLink>
  ) : showUpgradeComingSoon ? (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="shrink-0 font-medium text-muted-foreground/60">
          {t('Upgrade')}
        </span>
      </TooltipTrigger>
      <TooltipContent side="top" className="max-w-xs text-[12px]">
        {formatDedicatedDatabaseRegionUnavailableDescription(t)}
      </TooltipContent>
    </Tooltip>
  ) : null

  const specLabel =
    resolved.displayParts.variant === 'serverless'
      ? t('Serverless')
      : resolved.displayParts.variant === 'label'
        ? resolved.displayParts.label ?? resolved.specTooltip
        : null

  const specContent = (
    <>
      <Tooltip>
        <TooltipTrigger asChild>
          {canManageCompute ? (
            <Link
              {...resolved.computeLink}
              className="min-w-0 flex-1 overflow-hidden transition-colors hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
              aria-label={resolved.specTooltip}
            >
              <DatabaseSidebarComputeSpecDisplay
                parts={resolved.displayParts}
                label={specLabel}
              />
            </Link>
          ) : (
            <span
              className="min-w-0 flex-1 overflow-hidden"
              aria-label={resolved.specTooltip}
            >
              <DatabaseSidebarComputeSpecDisplay
                parts={resolved.displayParts}
                label={specLabel}
              />
            </span>
          )}
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-xs text-[12px]">
          {resolved.specTooltip}
        </TooltipContent>
      </Tooltip>
      {upgradeLink}
    </>
  )

  const strip = (
    <TooltipProvider delayDuration={0}>
      <div className={DATABASE_SIDEBAR_FOOTER_STRIP_ROW_CLASS}>{specContent}</div>
    </TooltipProvider>
  )

  if (variant === 'standalone') {
    return (
      <div className={DATABASE_SIDEBAR_FOOTER_STRIP_CLASS}>{strip}</div>
    )
  }

  return (
    <div
      data-sidebar-spec=""
      className={cn(
        DATABASE_SIDEBAR_STRIP_FULL_BLEED_CLASS,
        DATABASE_SIDEBAR_FOOTER_STRIP_CLASS,
      )}
    >
      {strip}
    </div>
  )
}
